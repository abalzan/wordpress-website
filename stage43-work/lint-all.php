<?php
/**
 * Stage 4.3 — fast syntax lint over every PHP file in the repository.
 *
 * Usage: php stage43-work/lint-all.php <repo-root>
 * Exits non-zero when any file fails to parse.
 */
$root = rtrim( $argv[1] ?? dirname( __DIR__ ), '/' );
$it = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS )
);
$total = 0;
$bad   = array();
foreach ( $it as $file ) {
	$path = $file->getPathname();
	if ( $file->getExtension() !== 'php' ) {
		continue;
	}
	if ( false !== strpos( $path, '/.git/' ) ) {
		continue;
	}
	++$total;
	try {
		token_get_all( file_get_contents( $path ), TOKEN_PARSE );
	} catch ( ParseError $e ) {
		$bad[] = $path . ' :: ' . $e->getMessage();
	}
}
echo "PHP files linted: {$total}\n";
echo 'Parse errors: ' . count( $bad ) . "\n";
foreach ( $bad as $b ) {
	echo "  FAIL {$b}\n";
}
exit( $bad ? 1 : 0 );
