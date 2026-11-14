<?php
/** Best-effort language compatibility only; never substitutes for native runtime execution. */
declare(strict_types=1);
require dirname(__DIR__).'/tools/vendor/autoload.php';
$files=[];foreach(array_slice($argv,1) as $root)foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS)) as $f)if($f->isFile()&&$f->getExtension()==='php')$files[]=$f->getPathname();
if(!$files)throw new RuntimeException('Runtime roots required');
foreach(['8.2','8.3'] as $version){$parser=(new PhpParser\ParserFactory)->createForVersion(PhpParser\PhpVersion::fromString($version));$finder=new PhpParser\NodeFinder;
 foreach($files as $file){$ast=$parser->parse(file_get_contents($file));
  foreach($finder->find($ast,static fn($n)=>$n instanceof PhpParser\Node\PropertyHook || ($n instanceof PhpParser\Node\Stmt\Property&&($n->flags&(PhpParser\Modifiers::PUBLIC_SET|PhpParser\Modifiers::PROTECTED_SET|PhpParser\Modifiers::PRIVATE_SET)))) as $n)throw new RuntimeException('Newer syntax '.$file.':'.$n->getStartLine());
 }
 echo 'PASS: '.count($files).' runtime PHP files pass PHP '.$version." best-effort static parsing; NOT native execution\n";
}
