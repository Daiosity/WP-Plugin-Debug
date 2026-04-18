param()

$ErrorActionPreference = 'Stop'

# Fixed slug. Do not derive from the current folder name.
$Slug = 'conflict-debugger'
$RootDir = Split-Path -Parent $PSScriptRoot
$BuildDir = Join-Path $RootDir 'build'
$DistDir = Join-Path $RootDir 'build'
$StagingRoot = Join-Path $BuildDir $Slug
$ZipPath = Join-Path $DistDir ($Slug + '.zip')

# Explicit include list keeps the package predictable.
$IncludeItems = @(
    'conflict-debugger.php',
    'includes',
    'assets',
    'languages',
    'uninstall.php',
    'readme.txt'
)

$ExcludeNames = @('.git', 'node_modules', 'dist', 'build')
$ExcludeFilePatterns = @('*.log', '*.map', '.DS_Store')

function Remove-Junk {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Path
    )

    foreach ($name in $ExcludeNames) {
        Get-ChildItem -LiteralPath $Path -Recurse -Force -Directory -ErrorAction SilentlyContinue |
            Where-Object { $_.Name -eq $name } |
            Remove-Item -Recurse -Force -ErrorAction SilentlyContinue
    }

    foreach ($pattern in $ExcludeFilePatterns) {
        Get-ChildItem -LiteralPath $Path -Recurse -Force -File -Filter $pattern -ErrorAction SilentlyContinue |
            Remove-Item -Force -ErrorAction SilentlyContinue
    }
}

Write-Host 'Preparing clean build directories...'
if (Test-Path $StagingRoot) {
    Remove-Item -LiteralPath $StagingRoot -Recurse -Force
}
if (Test-Path $ZipPath) {
    Remove-Item -LiteralPath $ZipPath -Force
}
if (!(Test-Path $BuildDir)) {
    New-Item -ItemType Directory -Path $BuildDir | Out-Null
}
if (!(Test-Path $DistDir)) {
    New-Item -ItemType Directory -Path $DistDir | Out-Null
}
New-Item -ItemType Directory -Path $StagingRoot | Out-Null

Write-Host 'Copying plugin files into staging area...'
foreach ($item in $IncludeItems) {
    $sourcePath = Join-Path $RootDir $item
    $destinationPath = Join-Path $StagingRoot $item

    if (!(Test-Path $sourcePath)) {
        throw "Missing required item: $item"
    }

    if ((Get-Item -LiteralPath $sourcePath).PSIsContainer) {
        Copy-Item -LiteralPath $sourcePath -Destination $StagingRoot -Recurse -Force
        Remove-Junk -Path $destinationPath
    }
    else {
        Copy-Item -LiteralPath $sourcePath -Destination $destinationPath -Force
    }
}

Write-Host 'Creating ZIP from the parent build directory so the root folder is preserved...'
Push-Location $BuildDir
try {
    Compress-Archive -LiteralPath $Slug -DestinationPath $ZipPath -Force
}
finally {
    Pop-Location
}

Write-Host "Build complete: $ZipPath"
