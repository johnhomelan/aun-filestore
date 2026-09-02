<?php
declare(strict_types=1);

/*
 * Admin HTTP UI plan, Stage 10d probe: render one real admin template
 * (index.tpl -> std-head + a Foreach over fake "services" + std-foot) natively,
 * through the compile-only Smarty shim + the build-time-stripped compiled
 * templates. Proves the templating vertical works under tpc before any
 * controller/router wiring.
 */

use Smarty\Smarty;

final class FakeService
{
    /** @param list<int> $aPorts */
    public function __construct(private string $sName, private array $aPorts) {}
    public function getName(): string { return $this->sName; }
    /** @return list<int> */
    public function getServicePorts(): array { return $this->aPorts; }
}

final class FakeEncap
{
    public function __construct(private string $sId, private string $sName, private string $sStatus) {}
    public function getId(): string { return $this->sId; }
    public function getName(): string { return $this->sName; }
    public function getStatus(): string { return $this->sStatus; }
}

function main(int $argc, array $argv): void
{
    $oSmarty = new Smarty();
    $oSmarty->assign('aServices', [
        new FakeService('FileServer', [0x99]),
        new FakeService('PrintServer', [0x9F, 0xD1]),
    ]);
    $oSmarty->assign('aEncapsulations', [
        new FakeEncap('aun', 'AUN', 'listening on 0.0.0.0:32768'),
        new FakeEncap('websocket', 'WebSocket', 'listening on 0.0.0.0:8090'),
    ]);

    $sHtml = $oSmarty->fetch('index.tpl');

    // crude assertions - fail loudly if the render is wrong
    $bOk = str_contains($sHtml, '<title>AUN Server: Admin</title>')
        && str_contains($sHtml, '>FileServer<')            // Foreach item, link text
        && str_contains($sHtml, 'service?port=159')        // aPorts[0] of PrintServer chain
        && str_contains($sHtml, '159, 209')               // implodemod ', ' glue
        && str_contains($sHtml, 'AUN Filestore')           // std-head sub-template
        && str_contains($sHtml, 'listening on 0.0.0.0:8090'); // encapsulation Foreach

    echo $sHtml;
    echo "\n----\n";
    echo ($bOk ? 'PASS' : 'FAIL') . " (len=" . strlen($sHtml) . ")\n";
}
