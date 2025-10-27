Write-Host ""
Write-Host "🔍 FILE-PROCESSOR ENVIRONMENT CHECK" -ForegroundColor Cyan
Write-Host "-----------------------------------"

$projectPath = Split-Path -Parent $MyInvocation.MyCommand.Definition
$envFile = Join-Path $projectPath ".env.local"
$phpPath = "php"

if (-Not (Test-Path $envFile)) {
    Write-Host "❌ File .env.local non trovato in $projectPath" -ForegroundColor Red
    exit 1
}

# 1️⃣ Mostra le righe principali e cerca anomalie di encoding/BOM
Write-Host "`n📄 Analisi file .env.local"
$bytes = Get-Content -Encoding Byte $envFile -TotalCount 3
if ($bytes[0] -eq 239 -and $bytes[1] -eq 187 -and $bytes[2] -eq 191) {
    Write-Host "⚠️  Il file contiene BOM UTF-8. Rimuovilo: salva come 'UTF-8 (senza BOM)'" -ForegroundColor Yellow
} else {
    Write-Host "✅ Nessun BOM UTF-8 rilevato."
}

Write-Host "`n🔎 Variabili chiave dal file:"
Get-Content $envFile | Select-String -Pattern "SOURCE_BASE_PATH|OUTPUT_BASE_PATH|TAVOLE_BASE_PATH"

# 2️⃣ Test accesso UNC diretto
function Test-UNC($label, $path) {
    if ([string]::IsNullOrWhiteSpace($path)) {
        Write-Host "❌ $label → variabile vuota" -ForegroundColor Red
        return
    }

    # Rimuove eventuali doppi apici o slash errati
    $clean = $path.Trim('"').Replace('\\\\', '\')

    if (Test-Path $clean) {
        Write-Host "✅ $label accessibile → $clean" -ForegroundColor Green
    } else {
        Write-Host "❌ $label non accessibile → $clean" -ForegroundColor Red
    }
}

Write-Host "`n🌐 Test accesso share (Windows)"
$envVars = @{}
foreach ($line in Get-Content $envFile) {
    if ($line -match '^(SOURCE_BASE_PATH|OUTPUT_BASE_PATH|TAVOLE_BASE_PATH)=(.+)$') {
        $envVars[$matches[1]] = $matches[2]
    }
}

Test-UNC "SOURCE_BASE_PATH" $envVars["SOURCE_BASE_PATH"]
Test-UNC "OUTPUT_BASE_PATH" $envVars["OUTPUT_BASE_PATH"]
Test-UNC "TAVOLE_BASE_PATH" $envVars["TAVOLE_BASE_PATH"]

# 3️⃣ Test lettura da PHP
Write-Host "`n🐘 Lettura variabili da PHP (.env.local)"
$phpCode = @"
require 'vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->loadEnv(__DIR__.'/.env.local');
echo "SOURCE_BASE_PATH=" . getenv('SOURCE_BASE_PATH') . PHP_EOL;
echo "OUTPUT_BASE_PATH=" . getenv('OUTPUT_BASE_PATH') . PHP_EOL;
echo "TAVOLE_BASE_PATH=" . getenv('TAVOLE_BASE_PATH') . PHP_EOL;
"@
$phpResult = & $phpPath -r $phpCode
if ($LASTEXITCODE -ne 0) {
    Write-Host "❌ Errore nell'esecuzione di PHP. Controlla che sia nel PATH." -ForegroundColor Red
} else {
    if ($phpResult) {
        Write-Host $phpResult -ForegroundColor Cyan
    } else {
        Write-Host "⚠️  PHP non ha letto nessuna variabile. Possibile problema nel formato .env.local." -ForegroundColor Yellow
    }
}

Write-Host "`n✅ Check completo terminato."
