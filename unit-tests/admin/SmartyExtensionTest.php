<?php

/*
 * @group unit-tests
 *
 * Tests for:
 *   HomeLan\FileStore\Admin\Smarty\IfIsObjectCompiler — compile() method
 *   HomeLan\FileStore\Admin\Smarty\Extension          — getModifierCompiler()
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use HomeLan\FileStore\Admin\Smarty\IfIsObjectCompiler;
use HomeLan\FileStore\Admin\Smarty\Extension;
use Smarty\CompilerException;

class SmartyExtensionTest extends TestCase
{
    // -----------------------------------------------------------------------
    // IfIsObjectCompiler::compile()
    // -----------------------------------------------------------------------

    private function makeCompiler(): \Smarty\Compiler\Template
    {
        return $this->createMock(\Smarty\Compiler\Template::class);
    }

    public function testCompileWithOneParamReturnsIsObjectExpression(): void
    {
        $oCompiler   = $this->makeCompiler();
        $oModifier   = new IfIsObjectCompiler();
        $sResult     = $oModifier->compile(['$myVar'], $oCompiler);
        $this->assertSame('is_object($myVar)', $sResult);
    }

    public function testCompilePreservesParamContentVerbatim(): void
    {
        $oCompiler = $this->makeCompiler();
        $oModifier = new IfIsObjectCompiler();
        $sResult   = $oModifier->compile(['$foo->bar'], $oCompiler);
        $this->assertSame('is_object($foo->bar)', $sResult);
    }

    public function testCompileWithZeroParamsThrowsCompilerException(): void
    {
        $oCompiler = $this->makeCompiler();
        $oModifier = new IfIsObjectCompiler();
        $this->expectException(CompilerException::class);
        $oModifier->compile([], $oCompiler);
    }

    public function testCompileWithTwoParamsThrowsCompilerException(): void
    {
        $oCompiler = $this->makeCompiler();
        $oModifier = new IfIsObjectCompiler();
        $this->expectException(CompilerException::class);
        $oModifier->compile(['$a', '$b'], $oCompiler);
    }

    public function testCompileWithThreeParamsThrowsCompilerException(): void
    {
        $oCompiler = $this->makeCompiler();
        $oModifier = new IfIsObjectCompiler();
        $this->expectException(CompilerException::class);
        $oModifier->compile(['$a', '$b', '$c'], $oCompiler);
    }

    // -----------------------------------------------------------------------
    // Extension::getModifierCompiler()
    // -----------------------------------------------------------------------

    public function testGetModifierCompilerForIsObjectReturnsIfIsObjectCompiler(): void
    {
        $oExt    = new Extension();
        $oResult = $oExt->getModifierCompiler('is_object');
        $this->assertInstanceOf(IfIsObjectCompiler::class, $oResult);
    }

    public function testGetModifierCompilerForUnknownModifierReturnsNull(): void
    {
        $oExt = new Extension();
        $this->assertNull($oExt->getModifierCompiler('unknown_modifier'));
    }

    public function testGetModifierCompilerForIsArrayReturnsNull(): void
    {
        $oExt = new Extension();
        $this->assertNull($oExt->getModifierCompiler('is_array'));
    }

    public function testGetModifierCompilerReturnsNewInstanceEachCall(): void
    {
        $oExt = new Extension();
        $oA   = $oExt->getModifierCompiler('is_object');
        $oB   = $oExt->getModifierCompiler('is_object');
        $this->assertNotSame($oA, $oB);
    }
}
