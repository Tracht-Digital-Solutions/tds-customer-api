<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Action\Admin;

use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Tds\CustomerApi\Action\BaseAction;

/**
 * GET /admin/projects — flat list of projects (with customer email +
 * milestone summary) for admin pickers. Not customer-scoped because
 * the route is gated by AdminAuthMiddleware.
 */
final class ListProjectsAction extends BaseAction
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function __invoke(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $sql = 'SELECT p.id, p.customer_id, p.title, p.status, p.start_date, p.target_date, '
            . 'c.name AS customer_name, c.email AS customer_email '
            . 'FROM project p '
            . 'INNER JOIN customer c ON c.id = p.customer_id '
            . 'ORDER BY p.id DESC LIMIT 500';
        $stmt = $this->pdo->query($sql);
        $projects = $stmt === false ? [] : $stmt->fetchAll();

        if ($projects === []) {
            return $this->json($response, 200, ['projects' => []]);
        }

        $ids = array_map(static fn ($p) => (int) $p['id'], $projects);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $ms = $this->pdo->prepare(
            "SELECT id, project_id, title, status, due_date FROM milestone "
            . "WHERE project_id IN ($placeholders) ORDER BY sort_order ASC, id ASC"
        );
        $ms->execute($ids);
        $milestones = $ms->fetchAll();

        $byProject = [];
        foreach ($milestones as $m) {
            $pid = (int) $m['project_id'];
            $byProject[$pid] = $byProject[$pid] ?? [];
            $byProject[$pid][] = $m;
        }
        foreach ($projects as &$p) {
            $p['milestones'] = $byProject[(int) $p['id']] ?? [];
        }
        unset($p);

        return $this->json($response, 200, ['projects' => $projects]);
    }
}
