<?php

declare(strict_types=1);

/*
 * Compile-only Smarty runtime shim for the TypePHP native admin UI
 * (see PORTING-REACT.md "Stage 10c/10d").
 *
 * The real smarty/smarty runtime cannot run under tpc `mode: bin`: it loads
 * pre-compiled templates via `include $file` + a `$unifunc()` variable-function
 * call, neither of which exists in an AOT binary. Instead,
 * packaging/typephp/build-admin-templates.php strips the file-scope
 * `if (isFresh()) { ... }` wrapper off each compiled template so the bare
 * `content_*()` functions can be `sources`, and emits admin_template_dispatch.php
 * (a name -> unifunc table + a `switch()` invoker).
 *
 * This file supplies the tiny slice of the Smarty API those stripped templates
 * actually call - enumerated from src/include/classes/Admin/templates_c/*.tpl.php:
 *
 *   $_smarty_tpl :  getValue / getVariable / setVariable / hasVariable / assign
 *                   getSmarty / renderSubTemplate
 *                   ->cache_id ->compile_id ->cache_lifetime (read, ignored)
 *   ->getSmarty() : getRuntime('Foreach') / getModifierCallback(count|implodemod|ucfirst)
 *   Foreach runtime : init(...) / restore(...)
 *   \Smarty\Variable : ->value  ->iteration   (+ `clone`)
 *
 * It also replaces HomeLan\FileStore\Admin\Service\Smarty (the real one wires
 * BundledFileResource / a compiler-backed engine) - list THIS file in the admin
 * build's `sources`, not src/include/classes/Admin/Service/Smarty.php.
 */

namespace Smarty {

    class Variable
    {
        public mixed $value;
        public int $iteration = 0;

        public function __construct(mixed $value = null)
        {
            $this->value = $value;
        }
    }

    class Template
    {
        // Read by the stripped templates as renderSubTemplate() arguments; the
        // shim ignores their values. Declared so tpc sees real properties.
        public mixed $cache_id = null;
        public mixed $compile_id = null;
        public mixed $cache_lifetime = null;

        /** @var array<string, mixed> raw assigned values */
        private array $aData = [];

        /** @var array<string, Variable> live Variable objects (foreach targets) */
        private array $aVars = [];

        public function __construct(
            private readonly Smarty $oSmarty,
            array $aData = [],
        ) {
            $this->aData = $aData;
        }

        public function getSmarty(): Smarty
        {
            return $this->oSmarty;
        }

        public function getValue(string $sName): mixed
        {
            if (isset($this->aVars[$sName])) {
                return $this->aVars[$sName]->value;
            }
            return $this->aData[$sName] ?? null;
        }

        public function getVariable(string $sName): Variable
        {
            if (!isset($this->aVars[$sName])) {
                $this->aVars[$sName] = new Variable($this->aData[$sName] ?? null);
            }
            return $this->aVars[$sName];
        }

        public function setVariable(string $sName, Variable $oVariable): void
        {
            $this->aVars[$sName] = $oVariable;
        }

        public function hasVariable(string $sName): bool
        {
            return isset($this->aVars[$sName]) || \array_key_exists($sName, $this->aData);
        }

        public function assign(string $sName, mixed $mValue, bool $bNocache = false, mixed $mScope = null): void
        {
            $this->aData[$sName] = $mValue;
            if (isset($this->aVars[$sName])) {
                $this->aVars[$sName]->value = $mValue;
            }
        }

        /**
         * The stripped templates call this inline (no return captured) to emit a
         * sub-template - only ever 'file:std-head.tpl' / 'file:std-foot.tpl'.
         * $aExtraVars carries per-include vars (e.g. ['title' => '...']).
         */
        public function renderSubTemplate(
            string $sName,
            mixed $mCacheId = null,
            mixed $mCompileId = null,
            mixed $mCaching = 0,
            mixed $mCacheLifetime = null,
            array $aExtraVars = [],
            mixed $mScope = 0,
            mixed $mCurrentDir = null,
        ): void {
            $this->oSmarty->renderInto($sName, \array_merge($this->aData, $aExtraVars));
        }
    }

    class Smarty
    {
        /** @var array<string, mixed> */
        private array $aData = [];

        private ?\Smarty\Runtime\ForeachRuntime $oForeach = null;

        public function assign(string $sName, mixed $mValue): void
        {
            $this->aData[$sName] = $mValue;
        }

        /** Render a top-level template to a string (Admin controllers' entry point). */
        public function fetch(string $sName): string
        {
            \ob_start();
            $this->renderInto($sName, $this->aData);
            $sOut = \ob_get_clean();
            return $sOut === false ? '' : $sOut;
        }

        /**
         * Invoke a template's (stripped) compiled function against the active
         * output buffer. $aData is the full variable set for that template.
         */
        public function renderInto(string $sName, array $aData): void
        {
            $sKey = $sName;
            if (\str_starts_with($sKey, 'file:')) {
                $sKey = \substr($sKey, 5);
            }
            $aMap = \HomeLan\FileStore\Admin\Compiled\ADMIN_TPL_UNIFUNC;
            if (!isset($aMap[$sKey])) {
                throw new \RuntimeException("Smarty shim: unknown template \"{$sName}\"");
            }
            $oTpl = new Template($this, $aData);
            \HomeLan\FileStore\Admin\Compiled\admin_tpl_invoke($aMap[$sKey], $oTpl);
        }

        public function getRuntime(string $sType): \Smarty\Runtime\ForeachRuntime
        {
            // The stripped templates only ever ask for 'Foreach'.
            if ($sType !== 'Foreach') {
                throw new \RuntimeException("Smarty shim: unsupported runtime \"{$sType}\"");
            }
            return $this->oForeach ??= new \Smarty\Runtime\ForeachRuntime();
        }

        /**
         * Templates call the result immediately, e.g.
         *   getModifierCallback('implodemod')(', ', $aPorts)
         * Only count / implodemod / ucfirst are registered (Admin\Service\Smarty).
         */
        public function getModifierCallback(string $sName): \Closure
        {
            switch ($sName) {
                case 'count':
                    return static fn (mixed $mValue): int => \is_countable($mValue) ? \count($mValue) : 0;
                case 'implodemod':
                    return static fn (string $sGlue, array $aParts): string => \implode($sGlue, $aParts);
                case 'ucfirst':
                    return static fn (string $sValue): string => \ucfirst($sValue);
                default:
                    throw new \RuntimeException("Smarty shim: unknown modifier \"{$sName}\"");
            }
        }
    }
}

namespace Smarty\Runtime {

    class ForeachRuntime
    {
        /**
         * Smarty's {foreach} preamble. The stripped templates use it two ways:
         *   ->init($tpl, $arr, 'itemVar')
         *   ->init($tpl, $arr, 'itemVar', false, 'keyVar')
         * It must (a) make getVariable('itemVar'|'keyVar') return live Variable
         * objects the `foreach (... as $v->value => $k->value)` writes into, and
         * (b) return the iterable.
         */
        public function init(
            \Smarty\Template $oTpl,
            mixed $mFrom,
            string $sItemVar,
            mixed $mKeyVar = false,
            mixed $mName = null,
        ): mixed {
            $oTpl->getVariable($sItemVar);
            if (\is_string($mKeyVar) && $mKeyVar !== '') {
                $oTpl->getVariable($mKeyVar);
            }
            return $mFrom ?? [];
        }

        public function restore(\Smarty\Template $oTpl, int $iLevels = 1): void
        {
            // No nested-scope bookkeeping needed for the admin templates - they
            // clone/restore the loop Variable explicitly via setVariable().
        }
    }
}

namespace HomeLan\FileStore\Admin\Service {

    /**
     * Compile-only replacement for src/include/classes/Admin/Service/Smarty.php
     * (which builds a compiler-backed \Smarty\Smarty). Same public surface the
     * Admin controllers use: getSmarty()->{assign,fetch}.
     */
    class Smarty
    {
        public function getSmarty(): \Smarty\Smarty
        {
            return new \Smarty\Smarty();
        }
    }
}

namespace HomeLan\FileStore\ShareFs\Admin\Service {

    /**
     * Compile-only replacement for src/include/classes/ShareFs/Admin/Service/Smarty.php
     * - sharefsd's own admin UI. Identical surface / behaviour to the filestored
     * one above; kept separate only because the real classes are. (Unused in the
     * filestored build - a dead class costs nothing.)
     */
    class Smarty
    {
        public function getSmarty(): \Smarty\Smarty
        {
            return new \Smarty\Smarty();
        }
    }
}
