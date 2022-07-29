<?php

declare(strict_types=1);

namespace Rector\PostRector\Rector;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Use_;
use PHPStan\Reflection\ReflectionProvider;
use Rector\BetterPhpDocParser\PhpDocInfo\PhpDocInfoFactory;
use Rector\CodingStyle\ClassNameImport\ClassNameImportSkipper;
use Rector\CodingStyle\Node\NameImporter;
use Rector\Core\Configuration\Option;
use Rector\Core\PhpParser\Node\BetterNodeFinder;
use Rector\Core\Provider\CurrentFileProvider;
use Rector\Core\ValueObject\Application\File;
use Rector\NodeTypeResolver\PhpDoc\NodeAnalyzer\DocBlockNameImporter;
use Symplify\PackageBuilder\Parameter\ParameterProvider;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

final class NameImportingPostRector extends AbstractPostRector
{
    public function __construct(
        private readonly ParameterProvider $parameterProvider,
        private readonly NameImporter $nameImporter,
        private readonly DocBlockNameImporter $docBlockNameImporter,
        private readonly ClassNameImportSkipper $classNameImportSkipper,
        private readonly PhpDocInfoFactory $phpDocInfoFactory,
        private readonly ReflectionProvider $reflectionProvider,
        private readonly CurrentFileProvider $currentFileProvider,
        private readonly BetterNodeFinder $betterNodeFinder
    ) {
    }

    public function enterNode(Node $node): ?Node
    {
        if (! $this->parameterProvider->provideBoolParameter(Option::AUTO_IMPORT_NAMES)) {
            return null;
        }

        $file = $this->currentFileProvider->getFile();
        if (! $file instanceof File) {
            return null;
        }

        if (! $this->shouldApply($file)) {
            return null;
        }

        if ($node instanceof Name) {
            return $this->processNodeName($node, $file);
        }

        if ($this->parameterProvider->provideBoolParameter(Option::AUTO_IMPORT_DOC_BLOCK_NAMES)) {
            $phpDocInfo = $this->phpDocInfoFactory->createFromNodeOrEmpty($node);
            $this->docBlockNameImporter->importNames($phpDocInfo->getPhpDocNode(), $node);
        }

        return $node;
    }

    public function getPriority(): int
    {
        // this must run after NodeRemovingPostRector, sine renamed use imports can block next import
        return 600;
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Imports fully qualified names', [
            new CodeSample(
                <<<'CODE_SAMPLE'
class SomeClass
{
    public function run(App\AnotherClass $anotherClass)
    {
    }
}
CODE_SAMPLE
                ,
                <<<'CODE_SAMPLE'
use App\AnotherClass;

class SomeClass
{
    public function run(AnotherClass $anotherClass)
    {
    }
}
CODE_SAMPLE
            ),
        ]);
    }

    private function processNodeName(Name $name, File $file): ?Node
    {
        if ($name->isSpecialClassName()) {
            return $name;
        }

        // @todo test if old stmts or new stmts! or both? :)
        /** @var Use_[] $currentUses */
        $currentUses = $this->betterNodeFinder->findInstanceOf($file->getNewStmts(), Use_::class);

        if ($this->shouldImportName($name, $currentUses)) {
            return $this->nameImporter->importName($name, $file, $currentUses);
        }

        return null;
    }

    /**
     * @param Use_[] $currentUses
     */
    private function shouldImportName(Name $name, array $currentUses): bool
    {
        if (substr_count($name->toCodeString(), '\\') <= 1) {
            return true;
        }

        if (! $this->classNameImportSkipper->isFoundInUse($name, $currentUses)) {
            return true;
        }

        if ($this->classNameImportSkipper->isAlreadyImported($name, $currentUses)) {
            return true;
        }

        return $this->reflectionProvider->hasFunction(new Name($name->getLast()), null);
    }

    private function shouldApply(File $file): bool
    {
        if (! $this->parameterProvider->provideBoolParameter(Option::APPLY_AUTO_IMPORT_NAMES_ON_CHANGED_FILES_ONLY)) {
            return true;
        }

        return $file->hasContentChanged();
    }
}
