<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/activity_log.php';

/**
 * Pastilles rouges « nouveautés » de la sidebar (includes/activity_log.php) :
 * liste blanche des onglets (activity_log_unread_tab), texte « 99+ »
 * (activity_log_badge_label), HTML accessible de la pastille
 * (activity_log_unread_badge), clause de cloisonnement partagée avec le
 * journal (activity_log_scope_clause) et absence de pastille sans connexion
 * (activity_log_sidebar_counts). Aucune base de données requise.
 */
final class UnreadBadgeTest extends TestCase
{
	public function testTabWhitelist(): void
	{
		$this->assertSame('journal', activity_log_unread_tab('journal'));
		$this->assertSame('interventions', activity_log_unread_tab('interventions'));
		$this->assertNull(activity_log_unread_tab('Journal'));
		$this->assertNull(activity_log_unread_tab('taches'));
		$this->assertNull(activity_log_unread_tab("journal' OR 1=1 --"));
		$this->assertNull(activity_log_unread_tab(''));
	}

	public function testBadgeLabel(): void
	{
		$this->assertSame('', activity_log_badge_label(0));
		$this->assertSame('', activity_log_badge_label(-3));
		$this->assertSame('1', activity_log_badge_label(1));
		$this->assertSame('99', activity_log_badge_label(99));
		$this->assertSame('99+', activity_log_badge_label(100));
		$this->assertSame('99+', activity_log_badge_label(12345));
	}

	public function testBadgeHtmlEmptyWhenNothingNew(): void
	{
		$this->assertSame('', activity_log_unread_badge(0));
	}

	public function testBadgeHtmlIsAccessible(): void
	{
		$html = activity_log_unread_badge(3);
		$this->assertStringContainsString('class="nav-unread"', $html);
		$this->assertStringContainsString('role="img"', $html);
		$this->assertStringContainsString('aria-label="3 nouveautés"', $html);
		$this->assertStringContainsString('>3</span>', $html);

		$this->assertStringContainsString('aria-label="1 nouveauté"', activity_log_unread_badge(1));

		$many = activity_log_unread_badge(150);
		$this->assertStringContainsString('>99+</span>', $many);
		$this->assertStringContainsString('aria-label="Plus de 99 nouveautés"', $many);
	}

	public function testScopeClauseMatchesRoles(): void
	{
		$this->assertSame([[], []], activity_log_scope_clause('admin', 1));
		$this->assertSame([['i.idClient = ?'], [7]], activity_log_scope_clause('client', 7));
		$this->assertSame([['j.idGarage = ?'], [4]], activity_log_scope_clause('garage', 9, ['idGarage' => 4]));

		[$where, $params] = activity_log_scope_clause('technicien', 5);
		$this->assertCount(1, $where);
		$this->assertStringContainsString("typeTechnicien = 'INTERNE'", $where[0]);
		$this->assertSame([5, 5, 5, 5], $params);
	}

	public function testScopeClauseRefusesUnknownOrIncompleteScope(): void
	{
		$this->assertNull(activity_log_scope_clause('garage', 9));
		$this->assertNull(activity_log_scope_clause('garage', 9, ['idGarage' => 0]));
		$this->assertNull(activity_log_scope_clause('visiteur', 1));
	}

	public function testSidebarCountsWithoutConnection(): void
	{
		$zero = ['journal' => 0, 'interventions' => 0];
		$this->assertSame($zero, activity_log_sidebar_counts(null, 'client', 7));
		$this->assertSame($zero, activity_log_sidebar_counts('pas une connexion', 'admin', 1));
	}
}
