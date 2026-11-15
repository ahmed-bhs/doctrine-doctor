<?php

/*
 * This file is part of the Doctrine Doctor.
 * (c) 2025-2026 Ahmed EBEN HASSINE
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace AhmedBhs\DoctrineDoctor\Analyzer\Helper;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Parses the PHP files of the application (the src/ directory of the project) once, with names resolved,
 * for the analyzers that look at how the application uses Doctrine rather than at the mapping.
 */
final class ProjectSourceScanner
{
    /** @var array<string, list<Node\Stmt>>|null */
    private ?array $files = null;

    private readonly Parser $parser;

    /** @var list<string> */
    private readonly array $roots;

    /**
     * @param list<string> $sourceDirs directories of application code, relative to the project directory
     */
    public function __construct(string $projectDir, array $sourceDirs = ['src'])
    {
        $roots = [];
        foreach ($sourceDirs as $sourceDir) {
            $root = rtrim($projectDir, '/\\') . '/' . trim($sourceDir, '/\\');
            $real = realpath($root);
            $roots[] = str_replace('\\', '/', false !== $real ? $real : $root);
        }
        $this->roots = $roots;
        $this->parser = new ParserFactory()->createForNewestSupportedVersion();
    }

    /**
     * @return array<string, list<Node\Stmt>> statements by absolute file path
     */
    public function files(): array
    {
        if (null !== $this->files) {
            return $this->files;
        }

        $this->files = [];
        foreach ($this->roots as $root) {
            if (!is_dir($root)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                    continue;
                }

                $statements = $this->parse($file->getPathname());
                if (null !== $statements) {
                    $this->files[str_replace('\\', '/', $file->getPathname())] = $statements;
                }
            }
        }
        ksort($this->files);

        return $this->files;
    }

    /**
     * @return iterable<array{file: string, class: Node\Stmt\Class_}>
     */
    public function classes(): iterable
    {
        $finder = new NodeFinder();
        foreach ($this->files() as $file => $statements) {
            foreach ($finder->findInstanceOf($statements, Node\Stmt\Class_::class) as $class) {
                if (null !== $class->namespacedName) {
                    yield ['file' => $file, 'class' => $class];
                }
            }
        }
    }

    public function isProjectFile(string $file): bool
    {
        $file = str_replace('\\', '/', $file);
        return array_any($this->roots, fn ($root) => str_starts_with($file, $root . '/'));
    }

    /**
     * @return list<Node\Stmt>|null
     */
    private function parse(string $path): ?array
    {
        try {
            $statements = $this->parser->parse((string) file_get_contents($path));
        } catch (\PhpParser\Error) {
            return null;
        }

        if (null === $statements) {
            return null;
        }

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());

        /** @var list<Node\Stmt> $resolved */
        $resolved = $traverser->traverse($statements);

        return $resolved;
    }
}
