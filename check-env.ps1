Write-Host ""
Write-Host "FILE-PROCESSOR ENVIRONMENT CHECK"
Write-Host "-----------------------------------"

$projectPath = Split-Path -Parent $PSCommandPath
$envFile = Join-Path $projectPath ".env.local"
$phpPath = "php"

if (-not (Test-Path $envFile)) {
    Write-Host "File .env.local non trovato in $projectPath"
    exit 1
}

Write-Host "`nAnalisi file .env.local"
$bytes = Get-Content -Encoding Byte $envFile -TotalCount 3
if ($bytes.Length -ge 3 -and $bytes[0] -eq 239 -and $bytes[1] -eq 187 -and $bytes[2] -eq 191) {
    Write-Host "⚠️  Il file contiene BOM UTF-8. Salvalo come UTF-8 senza BOM."
} else {
    Write-Host "OK: Nessun BOM UTF-8 rilevato."
}

Write-Host "`nVariabili chiave dal file:"
Get-Content $envFile | Select-String -Pattern "SOURCE_BASE_PATH|OUTPUT_BASE_PATH|TAVOLE_BASE_PATH"

Write-Host "`nTest accesso share (Windows):"
foreach ($line in Get-Content $envFile) {
    if ($line -match '^(SOURCE_BASE_PATH|OUTPUT_BASE_PATH|TAVOLE_BASE_PATH)=(.+)$') {
        $key = $matches[1]
        $val = $matches[2].Trim('"').Replace('\\\\','\')
        if (Test-Path $val) {
            Write-Host "OK  $key → $val"
        } else {
            Write-Host "FAIL $key → $val"
        }
    }
}

Write-Host "`nTest lettura da PHP:"
$phpCode = @"
require 'vendor/autoload.php';
use Symfony\Component\Dotenv\Dotenv;
(new Dotenv())->loadEnv(__DIR__.'/.env.local');
foreach (['SOURCE_BASE_PATH','OUTPUT_BASE_PATH','TAVOLE_BASE_PATH'] as \$v) {
    echo \$v.'='.getenv(\$v).PHP_EOL;
}
"@
$tmp = Join-Path $projectPath "checkenv_tmp.php"
$phpCode | Out-File -Encoding ASCII $tmp
& $phpPath $tmp
Remove-Item $tmp -ErrorAction SilentlyContinue

Write-Host "`nCheck completato."
