<?php

namespace ShopSys\CodingStandards\CsFixer;

use PhpCsFixer\Fixer\DefinedFixerInterface;
use PhpCsFixer\Fixer\FixerInterface;
use PhpCsFixer\FixerDefinition\CodeSample;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\Tokenizer\Tokens;
use PhpCsFixer\Tokenizer\TokensAnalyzer;
use SplFileInfo;

/**
 * Original PHP CS NoUnusedImportsFixer modified to not remove uses in same namespace.
 */
class NoUnusedImportsFixer implements FixerInterface, DefinedFixerInterface
{
    /**
     * {@inheritdoc}
     */
    public function getDefinition()
    {
        return new FixerDefinition(
            'Unused use statements must be removed. Allows for uses in the same namespace.',
            [
                new CodeSample("<?php\nuse \\DateTime;\nuse \\Exception;\n\nnew DateTime();"),
            ]
        );
    }

    /**
     * {@inheritdoc}
     */
    public function isCandidate(Tokens $tokens)
    {
        return $tokens->isTokenKindFound(T_USE);
    }

    /**
     * {@inheritdoc}
     */
    public function isRisky()
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function fix(SplFileInfo $file, Tokens $tokens)
    {
        $tokensAnalyzer = new TokensAnalyzer($tokens);
        $useDeclarationsIndexes = $tokensAnalyzer->getImportUseIndexes();

        if (0 === count($useDeclarationsIndexes)) {
            return;
        }

        $useDeclarations = $this->getNamespaceUseDeclarations($tokens, $useDeclarationsIndexes);
        $namespaceDeclarations = $this->getNamespaceDeclarations($tokens);
        $contentWithoutUseDeclarations = $this->generateCodeWithoutPartials($tokens, array_merge($namespaceDeclarations, $useDeclarations));
        $useUsages = $this->detectUseUsages($contentWithoutUseDeclarations, $useDeclarations);

        $this->removeUnusedUseDeclarations($tokens, $useDeclarations, $useUsages);
    }

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return 'Shopsys/no_unused_imports';
    }

    /**
     * {@inheritdoc}
     */
    public function getPriority()
    {
        // should be run after the SingleImportPerStatementFixer
        return -10;
    }

    /**
     * {@inheritdoc}
     */
    public function supports(SplFileInfo $file)
    {
        $path = $file->getPathname();

        // some fixtures are auto-generated by Symfony and may contain unused use statements
        if (false !== strpos($path, DIRECTORY_SEPARATOR . 'Fixtures' . DIRECTORY_SEPARATOR) &&
            false === strpos($path, DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'Fixtures' . DIRECTORY_SEPARATOR)
        ) {
            return false;
        }

        return true;
    }

    /**
     * @param string $content
     * @param array  $useDeclarations
     * @return array
     */
    private function detectUseUsages($content, array $useDeclarations)
    {
        $usages = [];

        foreach ($useDeclarations as $shortName => $useDeclaration) {
            $usages[$shortName] = (bool)preg_match('/(?<![\$\\\\])\b' . preg_quote($shortName) . '\b/i', $content);
        }

        return $usages;
    }

    /**
     * @param Tokens $tokens
     * @param array  $partials
     * @return string
     */
    private function generateCodeWithoutPartials(Tokens $tokens, array $partials)
    {
        $content = '';

        foreach ($tokens as $index => $token) {
            $allowToAppend = true;

            foreach ($partials as $partial) {
                if ($partial['start'] <= $index && $index <= $partial['end']) {
                    $allowToAppend = false;
                    break;
                }
            }

            if ($allowToAppend) {
                $content .= $token->getContent();
            }
        }

        return $content;
    }

    /**
     * @param \PhpCsFixer\Tokenizer\Tokens $tokens
     * @return array
     */
    private function getNamespaceDeclarations(Tokens $tokens)
    {
        $namespaces = [];

        foreach ($tokens as $index => $token) {
            if (!$token->isGivenKind(T_NAMESPACE)) {
                continue;
            }

            $declarationEndIndex = $tokens->getNextTokenOfKind($index, [';', '{']);

            $namespaces[] = [
                'name' => trim($tokens->generatePartialCode($index + 1, $declarationEndIndex - 1)),
                'start' => $index,
                'end' => $declarationEndIndex,
            ];
        }

        return $namespaces;
    }

    /**
     * @param \PhpCsFixer\Tokenizer\Tokens $tokens
     * @param array $useIndexes
     * @return array
     */
    private function getNamespaceUseDeclarations(Tokens $tokens, array $useIndexes)
    {
        $uses = [];

        foreach ($useIndexes as $index) {
            $declarationEndIndex = $tokens->getNextTokenOfKind($index, [';', [T_CLOSE_TAG]]);
            $declarationContent = $tokens->generatePartialCode($index + 1, $declarationEndIndex - 1);
            if (false !== strpos($declarationContent, ',')    // ignore multiple use statements that should be split into few separate statements (for example: `use BarB, BarC as C;`)
                || false !== strpos($declarationContent, '{') // do not touch group use declarations until the logic of this is added (for example: `use some\a\{ClassD};`)
            ) {
                continue;
            }

            $declarationParts = preg_split('/\s+as\s+/i', $declarationContent);

            if (1 === count($declarationParts)) {
                $fullName = $declarationContent;
                $declarationParts = explode('\\', $fullName);
                $shortName = end($declarationParts);
                $aliased = false;
            } else {
                list($fullName, $shortName) = $declarationParts;
                $declarationParts = explode('\\', $fullName);
                $aliased = $shortName !== end($declarationParts);
            }

            $shortName = trim($shortName);

            $uses[$shortName] = [
                'fullName' => trim($fullName),
                'shortName' => $shortName,
                'aliased' => $aliased,
                'start' => $index,
                'end' => $declarationEndIndex,
            ];
        }

        return $uses;
    }

    /**
     * @param \PhpCsFixer\Tokenizer\Tokens $tokens
     * @param array $useDeclarations
     * @param array $useUsages
     */
    private function removeUnusedUseDeclarations(Tokens $tokens, array $useDeclarations, array $useUsages)
    {
        foreach ($useDeclarations as $shortName => $useDeclaration) {
            if (!$useUsages[$shortName]) {
                $this->removeUseDeclaration($tokens, $useDeclaration);
            }
        }
    }

    /**
     * @param \PhpCsFixer\Tokenizer\Tokens $tokens
     * @param array $useDeclaration
     */
    private function removeUseDeclaration(Tokens $tokens, array $useDeclaration)
    {
        for ($index = $useDeclaration['end'] - 1; $index >= $useDeclaration['start']; --$index) {
            $tokens->clearTokenAndMergeSurroundingWhitespace($index);
        }

        if ($tokens[$useDeclaration['end']]->equals(';')) {
            $tokens[$useDeclaration['end']]->clear();
        }

        $prevToken = $tokens[$useDeclaration['start'] - 1];

        if ($prevToken->isWhitespace()) {
            $prevToken->setContent(rtrim($prevToken->getContent(), " \t"));
        }

        if (!isset($tokens[$useDeclaration['end'] + 1])) {
            return;
        }

        $nextIndex = $useDeclaration['end'] + 1;
        $nextToken = $tokens[$nextIndex];

        if ($nextToken->isWhitespace()) {
            $content = ltrim($nextToken->getContent(), " \t");

            $content = preg_replace(
                "#^\r\n|^\n#",
                '',
                $content,
                1
            );

            $nextToken->setContent($content);
        }

        if ($prevToken->isWhitespace() && $nextToken->isWhitespace()) {
            $tokens->overrideAt($nextIndex, [T_WHITESPACE, $prevToken->getContent() . $nextToken->getContent()]);
            $prevToken->clear();
        }
    }
}