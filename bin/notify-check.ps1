#Requires -Version 5.1
<#
.SYNOPSIS
    Probe URLs on a schedule and shout only when something is wrong.

.DESCRIPTION
    Runs a set of HTTP checks; if any of them fails it POSTs a plain-text
    message to a webhook (ntfy.sh, Bark, DingTalk, Slack, your own endpoint -
    anything that accepts text). Silent when everything is fine, so you can
    let it run every five minutes without drowning in notifications.

    Exit code: 0 all checks passed / 1 at least one failed / 2 bad arguments.

.PARAMETER Url
    One or more URLs to probe. Comma-separated or repeated.

.PARAMETER Expect
    Acceptable status codes, default "200-399".

.PARAMETER TimeoutSec
    Per-request timeout, default 10.

.PARAMETER Webhook
    URL to POST to on failure. Use %7Btitle%7D / %7Bbody%7D placeholders if the
    service wants them, otherwise the body is plain text.

.PARAMETER WebhookTitle
    Title sent along with the message, default "site down".

.PARAMETER Quiet
    Print nothing when every check passes.

.EXAMPLE
    .\notify-check.ps1 -Url https://example.com/health

.EXAMPLE
    .\notify-check.ps1 -Url https://a.example/,https://b.example/ `
        -Webhook https://ntfy.sh/my-topic -Quiet
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string[]] $Url,

    [string] $Expect = '200-399',

    [int] $TimeoutSec = 10,

    [string] $Webhook = '',

    [string] $WebhookTitle = 'site down',

    [switch] $Quiet
)

$ErrorActionPreference = 'Stop'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

function Split-List([string[]] $Items) {
    return @($Items | ForEach-Object { $_ -split ',' } | ForEach-Object { $_.Trim() } | Where-Object { $_ })
}

function Test-Expect([int] $Code, [string] $Spec) {
    foreach ($chunk in ($Spec -split ',')) {
        $part = $chunk.Trim()
        if ($part -eq '') { continue }
        if ($part -match '^(\d{3})-(\d{3})$') {
            if ($Code -ge [int]$Matches[1] -and $Code -le [int]$Matches[2]) { return $true }
        } elseif ($part -match '^(\d{3})$') {
            if ($Code -eq [int]$Matches[1]) { return $true }
        }
    }
    return $false
}

$targets = Split-List $Url
if ($targets.Count -eq 0) {
    Write-Error '-Url is empty'
    exit 2
}

$failures = @()

foreach ($target in $targets) {
    $started = Get-Date
    $code = 0
    $note = ''
    try {
        $response = Invoke-WebRequest -Uri $target -Method Get -TimeoutSec $TimeoutSec `
            -MaximumRedirection 5 -UseBasicParsing -ErrorAction Stop
        $code = [int]$response.StatusCode
    } catch {
        $response = $_.Exception.Response
        if ($null -ne $response) {
            try { $code = [int]$response.StatusCode } catch { $code = 0 }
        }
        $note = $_.Exception.Message
        if ($note.Length -gt 160) { $note = $note.Substring(0, 160) }
    }
    $ms = [int]((Get-Date) - $started).TotalMilliseconds

    $ok = Test-Expect -Code $code -Spec $Expect
    if ($ok) {
        if (-not $Quiet) {
            Write-Output ("ok    {0}  {1}  {2} ms" -f $code, $target, $ms)
        }
        continue
    }

    $line = "{0} -> {1} (expect {2}, {3} ms){4}" -f `
        $target, $(if ($code -eq 0) { 'no response' } else { "HTTP $code" }), $Expect, $ms, `
        $(if ($note -ne '') { " :: $note" } else { '' })
    Write-Output ("FAIL  " + $line)
    $failures += $line
}

if ($failures.Count -eq 0) {
    exit 0
}

$body = $failures -join "`n"

if ($Webhook -ne '') {
    $payload = $body
    $title = $WebhookTitle
    if ($Webhook -match '%7Btitle%7D|\{title\}') {
        $payload = $Webhook.Replace('{title}', [Uri]::EscapeDataString($title)).Replace('%7Btitle%7D', [Uri]::EscapeDataString($title))
    }
    if ($Webhook -match '%7Bbody%7D|\{body\}') {
        $payload = $payload.Replace('{body}', [Uri]::EscapeDataString($body)).Replace('%7Bbody%7D', [Uri]::EscapeDataString($body))
    }

    $endpoint = $Webhook
    if ($endpoint -match '\{title\}|%7Btitle%7D|\{body\}|%7Bbody%7D') {
        # placeholder form: the whole URL is the message, send an empty POST
        $endpoint = $payload
        $payload = ''
    }

    try {
        if ($payload -eq '') {
            Invoke-RestMethod -Uri $endpoint -Method Post -TimeoutSec $TimeoutSec -ErrorAction Stop | Out-Null
        } else {
            Invoke-RestMethod -Uri $endpoint -Method Post -Body ([Text.Encoding]::UTF8.GetBytes($payload)) `
                -ContentType 'text/plain; charset=utf-8' -TimeoutSec $TimeoutSec -ErrorAction Stop | Out-Null
        }
        Write-Output 'notified'
    } catch {
        Write-Output ('notify failed: ' + $_.Exception.Message)
    }
}

exit 1
