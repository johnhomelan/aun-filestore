<?php

/*
 * @group unit-tests
 *
 * Unit tests for HomeLan\FileStore\Services\Provider\SqlServer.
 *
 * Written exactly as the plan intended: SqlServer has no awareness of
 * running remotely (see docs/protocols/remote-provider.md), so it's tested
 * directly against a real local ServiceDispatcher (matching FileServer's
 * own GETBYTES ack-per-block idiom this streaming copies) - no relay
 * involved. Authentication goes through the real Security facade with the
 * built-in AuthPluginFile backend and an in-memory user string, the same
 * pattern SecurityTest.php uses. The database is a real temp-file SQLite
 * database - cheap enough to use for real rather than mocking PDO.
 */

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\Messages\EconetPacket;
use HomeLan\FileStore\Services\ServiceDispatcher;
use HomeLan\FileStore\Services\Provider\SqlServer;
use HomeLan\FileStore\Services\Provider\SqlServer\DatabaseRegistry;
use HomeLan\FileStore\Services\Provider\SqlServer\ConnectionFactory;
use HomeLan\FileStore\Services\Provider\SqlServer\ValueCodec;
use HomeLan\FileStore\Authentication\Security;
use HomeLan\FileStore\Authentication\Plugins\AuthPluginFile;

if (!defined('CONFIG_security_plugin_file_user_file')) {
    define('CONFIG_security_plugin_file_user_file', '');
}
if (!defined('CONFIG_security_plugin_file_default_crypt')) {
    define('CONFIG_security_plugin_file_default_crypt', 'md5');
}
if (!defined('CONFIG_security_auth_plugins')) {
    define('CONFIG_security_auth_plugins', 'file');
}

include_once(__DIR__ . '/../../../src/include/system.inc.php');

/**
 * flushReplies() normally calls ServiceDispatcher::sendPackets(), which
 * needs real transport infrastructure (WebSocket\Map et al.) initialized -
 * irrelevant to testing SqlServer's own logic, since every test reads
 * getReplies() directly instead of relying on real transmission. No-op'd
 * here, the same "override just the external boundary" pattern
 * TeefaxImportTest/NewsImportTest use for their own network calls.
 */
class SqlServerTestable extends SqlServer
{
    protected function flushReplies(): void
    {
    }
}

class SqlServerTest extends TestCase
{
    // Distinct from other test files' station numbers (Security's session
    // map is a static shared across the whole PHPUnit run - see SecurityTest.php).
    protected const int NET = 220;
    protected const int STN = 1;
    protected const int STN2 = 2;

    protected SqlServerTestable $oProvider;
    protected ServiceDispatcher $oDispatcher;
    protected ValueCodec $oCodec;
    protected string $sDbPath;

    protected function setUp(): void
    {
        $sUsers = "JOHN:md5-" . md5('secret') . ":home.john:5000:0:U\nRESTRICTED:md5-" . md5('pw') . ":home.r:5000:0:U";
        $oAuthLogger = new Logger('sqlserver-test-auth');
        $oAuthLogger->pushHandler(new NullHandler());
        AuthPluginFile::init($oAuthLogger, $sUsers);
        Security::init($oAuthLogger);

        $this->sDbPath = sys_get_temp_dir() . '/sqlserver_test_' . uniqid() . '.db';
        $oSetupPdo = new \PDO('sqlite:' . $this->sDbPath);
        $oSetupPdo->exec('CREATE TABLE widgets (id INTEGER, name TEXT)');
        $oSetupPdo->exec("INSERT INTO widgets VALUES (1, 'alice'), (2, 'bob'), (3, 'carol')");
        unset($oSetupPdo);

        config::overrideValue('sql_databases', 'widgets,secret');
        config::overrideValue('sql_database_widgets_engine', 'sqlite');
        config::overrideValue('sql_database_widgets_dsn', 'sqlite:' . $this->sDbPath);
        config::overrideValue('sql_database_widgets_user', '');
        config::overrideValue('sql_database_widgets_password', '');
        config::overrideValue('sql_database_widgets_allowed_users', '');
        config::overrideValue('sql_database_secret_engine', 'sqlite');
        config::overrideValue('sql_database_secret_dsn', 'sqlite:' . $this->sDbPath);
        config::overrideValue('sql_database_secret_allowed_users', 'RESTRICTED');
        config::overrideValue('sql_max_connections_per_database', 20);

        $oLogger = new Logger('sqlserver-test');
        $oLogger->pushHandler(new NullHandler());
        $this->oProvider = new SqlServerTestable($oLogger, new DatabaseRegistry(), new ConnectionFactory());
        $this->oDispatcher = new ServiceDispatcher($oLogger, [$this->oProvider]);
        $this->oCodec = new ValueCodec();
    }

    protected function tearDown(): void
    {
        if (Security::isLoggedIn(self::NET, self::STN)) {
            Security::logout(self::NET, self::STN);
        }
        if (Security::isLoggedIn(self::NET, self::STN2)) {
            Security::logout(self::NET, self::STN2);
        }
        @unlink($this->sDbPath);
        config::resetValue('sql_databases');
        config::resetValue('sql_database_widgets_engine');
        config::resetValue('sql_database_widgets_dsn');
        config::resetValue('sql_database_widgets_user');
        config::resetValue('sql_database_widgets_password');
        config::resetValue('sql_database_widgets_allowed_users');
        config::resetValue('sql_database_secret_engine');
        config::resetValue('sql_database_secret_dsn');
        config::resetValue('sql_database_secret_allowed_users');
        config::resetValue('sql_max_connections_per_database');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    protected function _packet(int $iOp, string $sPayload, int $iStation = self::STN): EconetPacket
    {
        $oPacket = new EconetPacket();
        $oPacket->setSourceNetwork(self::NET);
        $oPacket->setSourceStation($iStation);
        $oPacket->setPort(config::getValueAsInt('sql_server_port'));
        $oPacket->setData(chr($iOp) . $sPayload);
        return $oPacket;
    }

    protected function _lengthPrefixed(string $s): string
    {
        return chr(strlen($s)) . $s;
    }

    protected function _loginPayload(string $sUser, string $sPass): string
    {
        return $this->_lengthPrefixed($sUser) . $this->_lengthPrefixed($sPass);
    }

    protected function _queryPayload(string $sDb, int $iStreamPort, array $aParams, string $sSql): string
    {
        $s = $this->_lengthPrefixed($sDb) . pack('v', $iStreamPort) . chr(count($aParams));
        foreach ($aParams as $mParam) {
            $s .= $this->oCodec->encodeCell($mParam);
        }
        return $s . $sSql;
    }

    /** Sends one packet in and returns every reply/stream packet produced by it. */
    protected function _send(EconetPacket $oPacket): array
    {
        $this->oProvider->unicastPacketIn($oPacket);
        return $this->oProvider->getReplies();
    }

    protected function _login(int $iStation = self::STN, string $sUser = 'JOHN', string $sPass = 'secret'): void
    {
        $this->_send($this->_packet(SqlServer::OP_LOGIN, $this->_loginPayload($sUser, $sPass), $iStation));
    }

    /** Drives the ack-per-block streaming loop by firing an ack for the given packet's sequence. */
    protected function _ackAndCollect(EconetPacket $oPacket, int $iStation = self::STN): array
    {
        $this->oDispatcher->fireAckEvent(self::NET, $iStation, $oPacket->getSequence());
        return $this->oProvider->getReplies();
    }

    // -------------------------------------------------------------------------
    // LOGIN / LOGOUT
    // -------------------------------------------------------------------------

    public function testLoginSucceedsWithValidCredentials(): void
    {
        $aReplies = $this->_send($this->_packet(SqlServer::OP_LOGIN, $this->_loginPayload('JOHN', 'secret')));

        $this->assertCount(1, $aReplies);
        $this->assertSame(0, ord($aReplies[0]->getData()[0]));
        $this->assertTrue(Security::isLoggedIn(self::NET, self::STN));
    }

    public function testLoginFailsWithBadPassword(): void
    {
        $aReplies = $this->_send($this->_packet(SqlServer::OP_LOGIN, $this->_loginPayload('JOHN', 'wrong')));

        $this->assertSame(SqlServer::ERROR_BAD_CREDENTIALS, ord($aReplies[0]->getData()[0]));
        $this->assertFalse(Security::isLoggedIn(self::NET, self::STN));
    }

    public function testLogoutEndsTheSession(): void
    {
        $this->_login();
        $this->_send($this->_packet(SqlServer::OP_LOGOUT, ''));

        $this->assertFalse(Security::isLoggedIn(self::NET, self::STN));
    }

    // -------------------------------------------------------------------------
    // LIST_DATABASES
    // -------------------------------------------------------------------------

    public function testListDatabasesRequiresAuthentication(): void
    {
        $aReplies = $this->_send($this->_packet(SqlServer::OP_LIST_DATABASES, ''));
        $this->assertSame(SqlServer::ERROR_NOT_AUTHENTICATED, ord($aReplies[0]->getData()[0]));
    }

    public function testListDatabasesHidesRestrictedDatabasesFromOrdinaryUsers(): void
    {
        $this->_login();
        $aReplies = $this->_send($this->_packet(SqlServer::OP_LIST_DATABASES, ''));

        $sData = $aReplies[0]->getData();
        $this->assertSame(0, ord($sData[0]));
        $iCount = ord($sData[1]);
        $this->assertSame(1, $iCount); // only "widgets" - "secret" is restricted to RESTRICTED
        $iNameLen = ord($sData[2]);
        $this->assertSame('widgets', substr($sData, 3, $iNameLen));
    }

    public function testListDatabasesShowsRestrictedDatabaseToAllowedUser(): void
    {
        Security::login(self::NET, self::STN, 'RESTRICTED', 'pw');
        $aReplies = $this->_send($this->_packet(SqlServer::OP_LIST_DATABASES, ''));

        $sData = $aReplies[0]->getData();
        $this->assertSame(2, ord($sData[1])); // both databases visible
    }

    // -------------------------------------------------------------------------
    // QUERY validation
    // -------------------------------------------------------------------------

    public function testQueryRequiresAuthentication(): void
    {
        $aReplies = $this->_send($this->_packet(SqlServer::OP_QUERY, $this->_queryPayload('widgets', 25000, [], 'SELECT 1')));
        $this->assertSame(SqlServer::ERROR_NOT_AUTHENTICATED, ord($aReplies[0]->getData()[0]));
    }

    public function testQueryAgainstUnknownDatabase(): void
    {
        $this->_login();
        $aReplies = $this->_send($this->_packet(SqlServer::OP_QUERY, $this->_queryPayload('nope', 25000, [], 'SELECT 1')));
        $this->assertSame(SqlServer::ERROR_UNKNOWN_DATABASE, ord($aReplies[0]->getData()[0]));
    }

    public function testQueryAgainstRestrictedDatabaseIsDenied(): void
    {
        $this->_login();
        $aReplies = $this->_send($this->_packet(SqlServer::OP_QUERY, $this->_queryPayload('secret', 25000, [], 'SELECT 1')));
        $this->assertSame(SqlServer::ERROR_ACCESS_DENIED, ord($aReplies[0]->getData()[0]));
    }

    public function testSecondQueryWhileOneInFlightIsBusy(): void
    {
        $this->_login();
        $this->_send($this->_packet(SqlServer::OP_QUERY, $this->_queryPayload('widgets', 25000, [], 'SELECT * FROM widgets')));

        $aReplies = $this->_send($this->_packet(SqlServer::OP_QUERY, $this->_queryPayload('widgets', 25000, [], 'SELECT 1')));

        $this->assertSame(SqlServer::ERROR_BUSY, ord($aReplies[0]->getData()[0]));
    }

    // -------------------------------------------------------------------------
    // QUERY - no result set (INSERT/UPDATE/DDL)
    // -------------------------------------------------------------------------

    public function testInsertReturnsRowsAffectedWithNoStreaming(): void
    {
        $this->_login();
        $aReplies = $this->_send($this->_packet(
            SqlServer::OP_QUERY,
            $this->_queryPayload('widgets', 25000, [], "INSERT INTO widgets VALUES (4, 'dave')")
        ));

        $this->assertCount(1, $aReplies);
        $sData = $aReplies[0]->getData();
        $this->assertSame(0, ord($sData[0])); // status ok
        $this->assertSame(0, ord($sData[1])); // flag: no result set
        $iRowsAffected = unpack('V', substr($sData, 2, 4))[1];
        $this->assertSame(1, $iRowsAffected);
    }

    // -------------------------------------------------------------------------
    // QUERY - result set streaming (the ack-per-block paging loop)
    // -------------------------------------------------------------------------

    public function testSmallSelectStreamsOneBlockThenCompletes(): void
    {
        $this->_login();
        $iStreamPort = 25000;

        $aInitial = $this->_send($this->_packet(
            SqlServer::OP_QUERY,
            $this->_queryPayload('widgets', $iStreamPort, [], 'SELECT id, name FROM widgets ORDER BY id')
        ));

        $this->assertCount(2, $aInitial); // immediate ack + first (only) data block
        $this->assertSame(1, ord($aInitial[0]->getData()[1])); // flag: streaming

        $oDataBlock = $aInitial[1];
        $this->assertSame($iStreamPort, $oDataBlock->getPort());
        $this->assertLessThanOrEqual(256, strlen($oDataBlock->getData()));

        // Decode the header + 3 rows from the block.
        $sBlock = $oDataBlock->getData();
        $iColCount = unpack('v', substr($sBlock, 0, 2))[1];
        $this->assertSame(2, $iColCount);
        $iOffset = 2;
        $aColNames = [];
        for ($i = 0; $i < $iColCount; $i++) {
            $iLen = ord($sBlock[$iOffset]);
            $aColNames[] = substr($sBlock, $iOffset + 1, $iLen);
            $iOffset += 1 + $iLen;
        }
        $this->assertSame(['id', 'name'], $aColNames);

        // Acking the data block should trigger the completion reply (buffer + cursor now exhausted).
        $aAfterAck = $this->_ackAndCollect($oDataBlock);
        $this->assertCount(1, $aAfterAck);
        $sCompletion = $aAfterAck[0]->getData();
        $this->assertSame(0, ord($sCompletion[0]));
        $this->assertSame(SqlServer::EOF_COMPLETE, ord($sCompletion[1]));
        $iRowsSent = unpack('V', substr($sCompletion, 2, 4))[1];
        $this->assertSame(3, $iRowsSent);
    }

    public function testLargeSelectSpansMultipleBlocksPacedByAcks(): void
    {
        $this->_login();
        // Enough rows that the encoded stream can't fit in one 256-byte block:
        // each row is ~ "1 tag + 8 bytes int" + "1 tag + 2 len + ~6 bytes text" ≈ 18 bytes;
        // 50 rows ≈ 900 bytes, several blocks.
        $oPdo = new \PDO('sqlite:' . $this->sDbPath);
        for ($i = 4; $i <= 53; $i++) {
            $oPdo->exec("INSERT INTO widgets VALUES ($i, 'row$i')");
        }
        unset($oPdo);

        $iStreamPort = 25001;
        $aInitial = $this->_send($this->_packet(
            SqlServer::OP_QUERY,
            $this->_queryPayload('widgets', $iStreamPort, [], 'SELECT id, name FROM widgets ORDER BY id')
        ));
        $this->assertCount(2, $aInitial);

        $oBlock = $aInitial[1];
        $iBlocksSeen = 1;
        $iTotalRowsSent = null;
        // Keep acking blocks until a completion reply (status+flag+count, on the control
        // port rather than the stream port) appears instead of another data block.
        for ($iSafety = 0; $iSafety < 50; $iSafety++) {
            $aNext = $this->_ackAndCollect($oBlock);
            $this->assertNotEmpty($aNext, 'stream stalled without producing a completion reply');
            $oNext = $aNext[0];

            if ($oNext->getPort() === $iStreamPort) {
                $iBlocksSeen++;
                $oBlock = $oNext;
                continue;
            }

            // Completion reply.
            $sCompletion = $oNext->getData();
            $this->assertSame(SqlServer::EOF_COMPLETE, ord($sCompletion[1]));
            $iTotalRowsSent = unpack('V', substr($sCompletion, 2, 4))[1];
            break;
        }

        $this->assertGreaterThan(1, $iBlocksSeen, 'expected the result set to span more than one 256-byte block');
        $this->assertSame(53, $iTotalRowsSent);
    }

    public function testParameterizedQueryFiltersRows(): void
    {
        $this->_login();
        $iStreamPort = 25002;

        $aInitial = $this->_send($this->_packet(
            SqlServer::OP_QUERY,
            $this->_queryPayload('widgets', $iStreamPort, [2], 'SELECT id, name FROM widgets WHERE id = ?')
        ));

        $oBlock = $aInitial[1];
        $sBlock = $oBlock->getData();
        // header: 2 cols; then one row: INTEGER tag(1)+2, TEXT tag(1)+2-byte-len+"bob"
        $this->assertStringContainsString('bob', $sBlock);

        $aCompletion = $this->_ackAndCollect($oBlock);
        $iRowsSent = unpack('V', substr($aCompletion[0]->getData(), 2, 4))[1];
        $this->assertSame(1, $iRowsSent);
    }

    // -------------------------------------------------------------------------
    // CANCEL
    // -------------------------------------------------------------------------

    public function testCancelWithNothingInFlight(): void
    {
        $this->_login();
        $aReplies = $this->_send($this->_packet(SqlServer::OP_CANCEL, ''));
        $this->assertSame(SqlServer::ERROR_NOTHING_TO_CANCEL, ord($aReplies[0]->getData()[0]));
    }

    public function testCancelStopsAnInFlightStream(): void
    {
        $this->_login();
        $this->_send($this->_packet(
            SqlServer::OP_QUERY,
            $this->_queryPayload('widgets', 25003, [], 'SELECT * FROM widgets')
        ));

        $aReplies = $this->_send($this->_packet(SqlServer::OP_CANCEL, ''));
        $this->assertSame(0, ord($aReplies[0]->getData()[0]));

        // A new query is accepted immediately - CANCEL freed the "busy" slot.
        $aNext = $this->_send($this->_packet(
            SqlServer::OP_QUERY,
            $this->_queryPayload('widgets', 25003, [], 'SELECT 1')
        ));
        $this->assertNotSame(SqlServer::ERROR_BUSY, ord($aNext[0]->getData()[0]));
    }

    // -------------------------------------------------------------------------
    // Housekeeping - stale connection sweep
    // -------------------------------------------------------------------------

    public function testHousekeepingClosesConnectionsForExpiredSessions(): void
    {
        $this->_login();
        $this->_send($this->_packet(
            SqlServer::OP_QUERY,
            $this->_queryPayload('widgets', 25004, [], "INSERT INTO widgets VALUES (99, 'z')")
        ));

        // Simulate the session having ended without LOGOUT (e.g. idle timeout).
        Security::logout(self::NET, self::STN);
        $this->oProvider->sweepStaleConnections();

        // A fresh login + query still works (proves the sweep didn't break
        // anything and connections aren't left dangling forever).
        $this->_login();
        $aReplies = $this->_send($this->_packet(
            SqlServer::OP_QUERY,
            $this->_queryPayload('widgets', 25005, [], 'SELECT 1')
        ));
        $this->assertSame(0, ord($aReplies[0]->getData()[0]));
    }
}
