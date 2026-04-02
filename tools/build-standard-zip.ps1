param(
	[string]$Version = '1.0.22'
)

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$root = Split-Path -Parent $PSScriptRoot
$buildRoot = Join-Path $root 'build'
$stagingRoot = Join-Path $buildRoot '_package-staging'
$wpPackageRoot = Join-Path $stagingRoot 'plugin-conflict-debugger'
$hostFlatRoot = Join-Path $stagingRoot '_host-flat'
$wpZipPath = Join-Path $buildRoot 'plugin-conflict-debugger-wp-admin.zip'
$hostZipPath = Join-Path $buildRoot 'plugin-conflict-debugger.zip'
$legacyHostZipPath = Join-Path $buildRoot 'plugin-conflict-debugger-host-extract.zip'

$requiredItems = @(
	'plugin-conflict-debugger.php',
	'readme.txt',
	'uninstall.php',
	'assets',
	'includes',
	'languages'
)

function New-NormalizedZip {
	param(
		[Parameter(Mandatory = $true)]
		[string]$SourceRoot,
		[Parameter(Mandatory = $true)]
		[string]$ZipPath,
		[string]$RootPrefix = ''
	)

	$zipArchive = [System.IO.Compression.ZipFile]::Open($ZipPath, [System.IO.Compression.ZipArchiveMode]::Create)

	try {
		$normalizedSourceRoot = [System.IO.Path]::GetFullPath($SourceRoot)

		if ($RootPrefix -ne '') {
			$rootEntryName = ($RootPrefix.TrimEnd('/')) + '/'
			$zipArchive.CreateEntry($rootEntryName) | Out-Null
		}

		Get-ChildItem -LiteralPath $normalizedSourceRoot -Recurse -Force | ForEach-Object {
			$fullName = [System.IO.Path]::GetFullPath($_.FullName)
			$relativePath = $fullName.Substring($normalizedSourceRoot.Length).TrimStart('\\', '/')
			$entryName = if ($RootPrefix -ne '') {
				($RootPrefix.TrimEnd('/') + '/' + ($relativePath -replace '\\', '/')).TrimStart('/')
			}
			else {
				($relativePath -replace '\\', '/')
			}

			if ($_.PSIsContainer) {
				if ($entryName -ne '') {
					$zipArchive.CreateEntry($entryName.TrimEnd('/') + '/') | Out-Null
				}
			}
			else {
				[System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zipArchive, $fullName, $entryName, [System.IO.Compression.CompressionLevel]::Optimal) | Out-Null
			}
		}
	}
	finally {
		$zipArchive.Dispose()
	}
}

if (!(Test-Path $buildRoot)) {
	New-Item -ItemType Directory -Path $buildRoot | Out-Null
}

if (Test-Path $stagingRoot) {
	Remove-Item -LiteralPath $stagingRoot -Recurse -Force
}

foreach ($item in $requiredItems) {
	$sourcePath = Join-Path $root $item
	if (!(Test-Path $sourcePath)) {
		throw "Missing required package item: $item"
	}
}

foreach ($zipPath in @($wpZipPath, $hostZipPath, $legacyHostZipPath)) {
	if (Test-Path $zipPath) {
		Remove-Item -LiteralPath $zipPath -Force
	}
}

New-Item -ItemType Directory -Path $stagingRoot | Out-Null
New-Item -ItemType Directory -Path $wpPackageRoot | Out-Null
New-Item -ItemType Directory -Path $hostFlatRoot | Out-Null

foreach ($item in $requiredItems) {
	$sourcePath = Join-Path $root $item
	Copy-Item -LiteralPath $sourcePath -Destination $wpPackageRoot -Recurse
	Copy-Item -LiteralPath $sourcePath -Destination $hostFlatRoot -Recurse
}

New-NormalizedZip -SourceRoot $wpPackageRoot -ZipPath $wpZipPath -RootPrefix 'plugin-conflict-debugger'
New-NormalizedZip -SourceRoot $hostFlatRoot -ZipPath $hostZipPath

Remove-Item -LiteralPath $stagingRoot -Recurse -Force

Get-ChildItem $wpZipPath, $hostZipPath | Select-Object Name, FullName, Length, LastWriteTime
