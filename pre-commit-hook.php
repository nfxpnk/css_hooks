<?php
if(empty($argv[0]) || empty($argv[1]) || empty($argv[2]) || empty($argv[3])) {
	echo 'Something is wrong.';
	exit;
}

if(!file_exists(__DIR__ . '/config.php')) {
	exitWithError('Please, copy and rename ' . __DIR__ . '/config.php.example to config.php.');
}

# Main configuration
require __DIR__ . '/config.php';

# Verify commit message
$commitMessageFilePath = trim($argv[3]);
$commitMessage = file_get_contents($commitMessageFilePath);
$commitMessage = trim($commitMessage);
if(empty($commitMessage)) {
	exitWithError('Commit message can not be empty.');
}

if(preg_match("#^[A-Z]{3,5}\-\d{1,6}#", $commitMessage) === 0) {
	exitWithError('Commit message should start with JIRA issue key.');
}

$filesToCommitFilePath = trim($argv[1]);
$filesToVerify = file($filesToCommitFilePath);

foreach($filesToVerify as $filePath) {
	$filePath = trim($filePath);

	if(
		# Ignore empty lines
		empty($filePath) ||
		# Ignore not *.css files
		!preg_match("#\.css$#i", $filePath) ||
		# Ignore deleted files
		!file_exists($filePath)
	) {
		continue;
	}

	# File verification: csslint
	ob_start();
	system($cssLintCliPath . ' --errors=known-properties,errors "' . $filePath . '"');
	$cssLintOutput = ob_get_contents();
	ob_end_clean();

	$cssLintOutput = trim($cssLintOutput);
	if(strpos($cssLintOutput, 'csslint: No errors') !== 0) {
		exitWithError($cssLintOutput);
	}

	# File verification: csscomb
	$fileBasename = basename($filePath);
	$tempCssCombFilePath = $tempDirectory . '/' . preg_replace('#\.css$#i', '.csscomb.css', $fileBasename);
	$tempOriginalFilePath = $tempDirectory . '/' . $fileBasename;

	# Copy original file to temporary folder (for csscomb and diffutils)
	copy($filePath, $tempCssCombFilePath);
	copy($filePath, $tempOriginalFilePath);

	# Modify csscomb file for better results
	$tempCssCombFileContent = file_get_contents($tempCssCombFilePath);

	# Replace all comments into placeholders !$$!comments_var_!$$!
	preg_match_all("#\/\*(.*?)\*\/#msi", $tempCssCombFileContent, $matches);
	foreach($matches[0] as $key => $match) {
		$tempCssCombFileContent = str_replace($match, '!$$!comments_var_' . $key . '!$$!', $tempCssCombFileContent);
	}

	# Delete all spaces and tabs from start of line
	$tempCssCombFileContent = preg_replace("#^[ \t]+#msi", '', $tempCssCombFileContent);

	# Delete all spaces and tabs from end of line
	$tempCssCombFileContent = preg_replace("#[ \t]+(\r\n|\n)#msi", "\n", $tempCssCombFileContent);

	# Delete all spaces and tabs after }
	$tempCssCombFileContent = preg_replace("#\}[ \t]#msi", '}', $tempCssCombFileContent);

	# Replace spaces or tabs > 2 into one space
	$tempCssCombFileContent = preg_replace("#[ \t]+#msi", ' ', $tempCssCombFileContent);

	# Remove empty lines
	$tempCssCombFileContent = preg_replace("#^\s*(\r\n|\n)#mi", '', $tempCssCombFileContent);

	# Remove newlines after }
	$tempCssCombFileContent = preg_replace("#}(\n|\r\n)+#msi", '}', $tempCssCombFileContent);

	# Add two newlines character after }
	$tempCssCombFileContent = str_replace("}", "}\n\n", $tempCssCombFileContent);

	# Put back our css comments instead of out placeholders
	foreach($matches[0] as $key => $match) {
		$tempCssCombFileContent = str_replace('!$$!comments_var_' . $key . '!$$!', $match, $tempCssCombFileContent);
	}

	# Format comments newlines
	$tempCssCombFileContent = preg_replace("#\*\/\s+\/\*#msi", "*/\n/*", $tempCssCombFileContent);

	# Format all newlines to \n only
	$tempCssCombFileContent = str_replace("\r\n", "\n", $tempCssCombFileContent);

	# Write modified data to file
	file_put_contents($tempCssCombFilePath, $tempCssCombFileContent);

	# Execute csscomb
	system($cssCombCliPath . ' --config "' . $cssCombConfigFilePath . '" "' . $tempCssCombFilePath . '"');

	# Modify temp original file
	$tempOriginalFileContent = file_get_contents($tempOriginalFilePath);
	$tempOriginalFileContent = str_replace("\r\n", "\n", $tempOriginalFileContent);
	$tempOriginalFileContent = preg_replace("#\n+$#", "\n", $tempOriginalFileContent);
	file_put_contents($tempOriginalFilePath, $tempOriginalFileContent);

	# Create *.patch file to see is there anything needs to be fixed
	system('"' . $diffCliPath . '" -Naur "' . $tempOriginalFilePath . '" "' . $tempCssCombFilePath . '" > "' . $patchFilePath . '"');

	# Remove temp files
	unlink($tempOriginalFilePath);
	unlink($tempCssCombFilePath);

	$patchFileSize = filesize($patchFilePath);

	if($patchFileSize > 0) {
		exitWithError('File ' . $filePath . ' is not perfect.' . "\n" . 'See: ' . $patchFilePath . ' for details.');
	}

	# Delete patch file if it's empty
	unlink($patchFilePath);
}

function exitWithError($errorMessage) {
	$stderr = fopen('php://stderr', 'w');
	fwrite($stderr, $errorMessage);
	fclose($stderr);
	exit(1);
}


