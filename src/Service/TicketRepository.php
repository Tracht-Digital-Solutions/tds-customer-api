<?php
declare(strict_types=1);

namespace Tds\CustomerApi\Service;

use PDO;

/**
 * Queries + presentation for tickets, their comments and attachments. Centralised
 * here (rather than inline in each action, as most resources do) because the
 * ticket read model joins the configurable status registry and applies
 * per-audience visibility rules that must stay consistent across every endpoint.
 */
final class TicketRepository
{
    private const SELECT =
        'SELECT t.id, t.customer_id, t.project_id, t.subject, t.description, t.priority, t.type, '
        . 't.assignee_user_id, t.created_by_type, t.created_by_user_id, t.source, '
        . 't.from_name, t.from_email, t.from_company, '
        . 't.customer_action_required, t.customer_action_note, t.created_at, t.updated_at, t.closed_at, '
        . 's.id AS status_id, s.name AS status_name, s.color AS status_color, '
        . 's.visible_to_customer AS status_visible, s.is_terminal AS status_terminal '
        . 'FROM ticket t INNER JOIN ticket_status s ON s.id = t.status_id';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Tickets for one customer, newest activity first. Statuses are resolved for
     * the customer audience (internal-only statuses show a neutral fallback).
     *
     * @return list<array<string,mixed>>
     */
    public function customerList(int $customerId): array
    {
        $stmt = $this->pdo->prepare(
            self::SELECT . ' WHERE t.customer_id = :cid ORDER BY t.updated_at DESC, t.id DESC'
        );
        $stmt->execute(['cid' => $customerId]);
        return array_map(fn (array $r) => $this->present($r, forCustomer: true), $stmt->fetchAll());
    }

    /**
     * Admin list with optional filters + the customer's name/email for display.
     *
     * @param array{status_id?:int,assignee_user_id?:int,priority?:string,customer_id?:int,q?:string} $filters
     * @return list<array<string,mixed>>
     */
    public function adminList(array $filters): array
    {
        $where = [];
        $params = [];
        if (isset($filters['status_id'])) {
            $where[] = 't.status_id = :status_id';
            $params['status_id'] = $filters['status_id'];
        }
        if (isset($filters['assignee_user_id'])) {
            $where[] = 't.assignee_user_id = :assignee';
            $params['assignee'] = $filters['assignee_user_id'];
        }
        if (isset($filters['priority'])) {
            $where[] = 't.priority = :priority';
            $params['priority'] = $filters['priority'];
        }
        if (isset($filters['type'])) {
            $where[] = 't.type = :type';
            $params['type'] = $filters['type'];
        }
        if (isset($filters['customer_id'])) {
            $where[] = 't.customer_id = :customer_id';
            $params['customer_id'] = $filters['customer_id'];
        }
        if (isset($filters['q']) && $filters['q'] !== '') {
            $where[] = '(t.subject LIKE :q OR t.description LIKE :q)';
            $params['q'] = '%' . $filters['q'] . '%';
        }

        // LEFT JOIN (not INNER): contact-form tickets have customer_id = NULL, so
        // an INNER JOIN would silently drop them. Display name/email fall back to
        // the stored from_* submitter details for those.
        $sql = 'SELECT t.id, t.customer_id, t.project_id, t.subject, t.description, t.priority, t.type, '
            . 't.assignee_user_id, t.created_by_type, t.created_by_user_id, t.source, '
            . 't.from_name, t.from_email, t.from_company, '
            . 't.customer_action_required, t.customer_action_note, t.created_at, t.updated_at, t.closed_at, '
            . 's.id AS status_id, s.name AS status_name, s.color AS status_color, '
            . 's.visible_to_customer AS status_visible, s.is_terminal AS status_terminal, '
            . 'c.name AS customer_name, c.email AS customer_email '
            . 'FROM ticket t '
            . 'INNER JOIN ticket_status s ON s.id = t.status_id '
            . 'LEFT JOIN customer c ON c.id = t.customer_id';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY t.updated_at DESC, t.id DESC LIMIT 500';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return array_map(function (array $r): array {
            $ticket = $this->present($r, forCustomer: false);
            $ticket['customerName'] = (string) ($r['customer_name'] ?? $r['from_name'] ?? '');
            $ticket['customerEmail'] = (string) ($r['customer_email'] ?? $r['from_email'] ?? '');
            return $ticket;
        }, $stmt->fetchAll());
    }

    /** @return array<string,mixed>|null raw joined row (unpresented) */
    public function findRow(int $id, ?int $customerId = null): ?array
    {
        $sql = self::SELECT . ' WHERE t.id = :id';
        $params = ['id' => $id];
        if ($customerId !== null) {
            $sql .= ' AND t.customer_id = :cid';
            $params['cid'] = $customerId;
        }
        $stmt = $this->pdo->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @param array<string,mixed> $data
     * @return int new ticket id
     */
    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ticket (customer_id, project_id, status_id, subject, description, priority, type, '
            . 'created_by_type, created_by_user_id, source, email_message_id, '
            . 'from_name, from_email, from_company, created_at, updated_at) '
            . 'VALUES (:cid, :pid, :sid, :subject, :description, :priority, :type, :cbt, :cbu, :src, :emid, '
            . ':fname, :femail, :fcompany, NOW(), NOW())'
        );
        $stmt->execute([
            'cid' => $data['customer_id'],
            'pid' => $data['project_id'],
            'sid' => $data['status_id'],
            'subject' => $data['subject'],
            'description' => $data['description'],
            'priority' => $data['priority'],
            'type' => $data['type'],
            'cbt' => $data['created_by_type'],
            'cbu' => $data['created_by_user_id'],
            // source: 'portal' (default) for in-app tickets, 'email' for IMAP-
            // ingested ones, 'contact' for contact-form submissions.
            // email_message_id threads/dedupes inbound mail.
            'src' => $data['source'] ?? 'portal',
            'emid' => $data['email_message_id'] ?? null,
            // from_*: submitter contact details for a non-customer ticket
            // (contact form); NULL for customer/portal tickets.
            'fname' => $data['from_name'] ?? null,
            'femail' => $data['from_email'] ?? null,
            'fcompany' => $data['from_company'] ?? null,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Whitelisted partial update. Recognised keys: status_id, priority, type,
     * assignee_user_id, project_id, customer_action_required,
     * customer_action_note, closed_at. Always bumps updated_at.
     *
     * @param array<string,mixed> $fields
     */
    public function update(int $id, array $fields): void
    {
        $allowed = [
            'status_id', 'priority', 'type', 'assignee_user_id', 'project_id',
            'customer_action_required', 'customer_action_note', 'closed_at',
        ];
        $sets = [];
        $params = ['id' => $id];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $fields)) {
                $sets[] = "{$key} = :{$key}";
                $params[$key] = $fields[$key];
            }
        }
        if ($sets === []) {
            return;
        }
        $sets[] = 'updated_at = NOW()';
        $stmt = $this->pdo->prepare('UPDATE ticket SET ' . implode(', ', $sets) . ' WHERE id = :id');
        $stmt->execute($params);
    }

    public function clearCustomerAction(int $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ticket SET customer_action_required = 0, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
    }

    public function touch(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE ticket SET updated_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function comments(int $ticketId, bool $includeInternal): array
    {
        $sql = 'SELECT id, ticket_id, author_type, author_user_id, body, is_internal, created_at, edited_at '
            . 'FROM ticket_comment WHERE ticket_id = :tid';
        if (!$includeInternal) {
            $sql .= ' AND is_internal = 0';
        }
        $sql .= ' ORDER BY created_at ASC, id ASC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['tid' => $ticketId]);
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'ticketId' => (int) $r['ticket_id'],
            'authorType' => (string) $r['author_type'],
            'authorUserId' => $r['author_user_id'] !== null ? (int) $r['author_user_id'] : null,
            'body' => (string) $r['body'],
            'isInternal' => (bool) $r['is_internal'],
            'createdAt' => (string) $r['created_at'],
            'editedAt' => $r['edited_at'] !== null ? (string) $r['edited_at'] : null,
        ], $stmt->fetchAll());
    }

    /** @param array<string,mixed> $data */
    public function addComment(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ticket_comment (ticket_id, author_type, author_user_id, body, is_internal, email_message_id, created_at) '
            . 'VALUES (:tid, :at, :au, :body, :internal, :emid, NOW())'
        );
        $stmt->execute([
            'tid' => $data['ticket_id'],
            'at' => $data['author_type'],
            'au' => $data['author_user_id'],
            'body' => $data['body'],
            'internal' => $data['is_internal'] ? 1 : 0,
            // Message-ID of the inbound email that produced this comment (IMAP
            // ingest), else null. Used to dedupe re-polled messages.
            'emid' => $data['email_message_id'] ?? null,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * True when a mail's Message-ID has already been ingested (as a ticket or a
     * comment). The IMAP poller uses this to skip re-delivered messages even if
     * marking \Seen failed on a prior pass.
     */
    public function emailMessageIdSeen(string $messageId): bool
    {
        if ($messageId === '') {
            return false;
        }
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM ticket WHERE email_message_id = :m '
            . 'UNION SELECT 1 FROM ticket_comment WHERE email_message_id = :m2 LIMIT 1'
        );
        $stmt->execute(['m' => $messageId, 'm2' => $messageId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Find an existing ticket by its originating email Message-ID (matched
     * against In-Reply-To / References headers) or by numeric id (parsed from a
     * "#<id>" subject marker), scoped to one customer so a sender can only append
     * to their own ticket. Returns the ticket id or null.
     */
    public function findForEmailReply(?int $ticketId, array $referenceIds, int $customerId): ?int
    {
        if ($ticketId !== null) {
            $stmt = $this->pdo->prepare('SELECT id FROM ticket WHERE id = :id AND customer_id = :cid LIMIT 1');
            $stmt->execute(['id' => $ticketId, 'cid' => $customerId]);
            $found = $stmt->fetchColumn();
            if ($found !== false) {
                return (int) $found;
            }
        }
        foreach ($referenceIds as $ref) {
            if ($ref === '') {
                continue;
            }
            $stmt = $this->pdo->prepare(
                'SELECT t.id FROM ticket t WHERE t.customer_id = :cid AND t.email_message_id = :m '
                . 'UNION '
                . 'SELECT c.ticket_id FROM ticket_comment c INNER JOIN ticket t2 ON t2.id = c.ticket_id '
                . 'WHERE t2.customer_id = :cid2 AND c.email_message_id = :m2 LIMIT 1'
            );
            $stmt->execute(['cid' => $customerId, 'm' => $ref, 'cid2' => $customerId, 'm2' => $ref]);
            $found = $stmt->fetchColumn();
            if ($found !== false) {
                return (int) $found;
            }
        }
        return null;
    }

    /** Resolve a customer id from an email address (customer.email is UNIQUE). */
    public function customerIdByEmail(string $email): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM customer WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /** @return list<array<string,mixed>> */
    public function attachments(int $ticketId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, ticket_id, comment_id, filename, mime_type, size_bytes, uploaded_by_type, created_at '
            . 'FROM ticket_attachment WHERE ticket_id = :tid ORDER BY created_at ASC, id ASC'
        );
        $stmt->execute(['tid' => $ticketId]);
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'ticketId' => (int) $r['ticket_id'],
            'commentId' => $r['comment_id'] !== null ? (int) $r['comment_id'] : null,
            'filename' => (string) $r['filename'],
            'mimeType' => (string) $r['mime_type'],
            'sizeBytes' => (int) $r['size_bytes'],
            'uploadedByType' => (string) $r['uploaded_by_type'],
            'createdAt' => (string) $r['created_at'],
        ], $stmt->fetchAll());
    }

    /** @param array<string,mixed> $data */
    public function addAttachment(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ticket_attachment (ticket_id, comment_id, filename, storage_path, mime_type, size_bytes, uploaded_by_type, created_at) '
            . 'VALUES (:tid, :cid, :fn, :sp, :mt, :sb, :ubt, NOW())'
        );
        $stmt->execute([
            'tid' => $data['ticket_id'],
            'cid' => $data['comment_id'],
            'fn' => $data['filename'],
            'sp' => $data['storage_path'],
            'mt' => $data['mime_type'],
            'sb' => $data['size_bytes'],
            'ubt' => $data['uploaded_by_type'],
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string,mixed>|null storage_path row scoped to a ticket */
    public function findAttachment(int $attachmentId, int $ticketId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT filename, storage_path, mime_type, size_bytes '
            . 'FROM ticket_attachment WHERE id = :aid AND ticket_id = :tid LIMIT 1'
        );
        $stmt->execute(['aid' => $attachmentId, 'tid' => $ticketId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Turn a joined ticket row into the API shape, resolving the status for the
     * audience. Customer callers never see an internal-only status label.
     *
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    public function present(array $r, bool $forCustomer): array
    {
        $status = [
            'id' => (int) $r['status_id'],
            'name' => (string) $r['status_name'],
            'color' => (string) $r['status_color'],
            'visibleToCustomer' => (bool) $r['status_visible'],
            'isTerminal' => (bool) $r['status_terminal'],
        ];
        if ($forCustomer) {
            $status = TicketStatusRepository::presentForCustomer($status);
        }

        return [
            'id' => (int) $r['id'],
            // NULL for contact-form tickets that don't belong to a customer.
            'customerId' => $r['customer_id'] !== null ? (int) $r['customer_id'] : null,
            'projectId' => $r['project_id'] !== null ? (int) $r['project_id'] : null,
            'subject' => (string) $r['subject'],
            'description' => (string) $r['description'],
            'priority' => (string) $r['priority'],
            'type' => (string) $r['type'],
            'source' => isset($r['source']) ? (string) $r['source'] : 'portal',
            'assigneeUserId' => $r['assignee_user_id'] !== null ? (int) $r['assignee_user_id'] : null,
            'createdByType' => (string) $r['created_by_type'],
            'createdByUserId' => $r['created_by_user_id'] !== null ? (int) $r['created_by_user_id'] : null,
            // Structured submitter contact details (contact-form tickets).
            'fromName' => isset($r['from_name']) && $r['from_name'] !== null ? (string) $r['from_name'] : null,
            'fromEmail' => isset($r['from_email']) && $r['from_email'] !== null ? (string) $r['from_email'] : null,
            'fromCompany' => isset($r['from_company']) && $r['from_company'] !== null ? (string) $r['from_company'] : null,
            'customerActionRequired' => (bool) $r['customer_action_required'],
            'customerActionNote' => $r['customer_action_note'] !== null ? (string) $r['customer_action_note'] : null,
            'statusId' => (int) $r['status_id'],
            'status' => $status,
            'createdAt' => (string) $r['created_at'],
            'updatedAt' => (string) $r['updated_at'],
            'closedAt' => $r['closed_at'] !== null ? (string) $r['closed_at'] : null,
        ];
    }

    /**
     * The address to notify for a ticket: the owning customer's email, or — for a
     * contact-form ticket with no customer — the stored submitter email. Returns
     * null when neither is available. Centralised here so the admin reply/status
     * actions notify contact submitters as well as customers.
     *
     * @param array<string,mixed> $row a raw joined ticket row (from findRow)
     */
    public function notifyEmail(array $row): ?string
    {
        if ($row['customer_id'] !== null) {
            $stmt = $this->pdo->prepare('SELECT email FROM customer WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => (int) $row['customer_id']]);
            $email = $stmt->fetchColumn();
            return $email === false ? null : (string) $email;
        }
        $from = $row['from_email'] ?? null;
        return $from !== null && $from !== '' ? (string) $from : null;
    }

    /**
     * Find a contact-form ticket (source='contact') a non-customer email reply
     * belongs to — the from_email counterpart of findForEmailReply(). Matches a
     * "#<id>" subject marker or an In-Reply-To/References Message-ID, scoped to
     * the sender's own from_email so a stranger can't append to someone else's
     * contact ticket. Returns the ticket id or null.
     *
     * @param list<string> $referenceIds
     */
    public function findContactTicketForReply(?int $ticketId, array $referenceIds, string $fromEmail): ?int
    {
        if ($fromEmail === '') {
            return null;
        }
        if ($ticketId !== null) {
            $stmt = $this->pdo->prepare(
                "SELECT id FROM ticket WHERE id = :id AND source = 'contact' AND from_email = :email LIMIT 1"
            );
            $stmt->execute(['id' => $ticketId, 'email' => $fromEmail]);
            $found = $stmt->fetchColumn();
            if ($found !== false) {
                return (int) $found;
            }
        }
        foreach ($referenceIds as $ref) {
            if ($ref === '') {
                continue;
            }
            $stmt = $this->pdo->prepare(
                "SELECT t.id FROM ticket t WHERE t.source = 'contact' AND t.from_email = :email AND t.email_message_id = :m "
                . 'UNION '
                . 'SELECT c.ticket_id FROM ticket_comment c INNER JOIN ticket t2 ON t2.id = c.ticket_id '
                . "WHERE t2.source = 'contact' AND t2.from_email = :email2 AND c.email_message_id = :m2 LIMIT 1"
            );
            $stmt->execute(['email' => $fromEmail, 'm' => $ref, 'email2' => $fromEmail, 'm2' => $ref]);
            $found = $stmt->fetchColumn();
            if ($found !== false) {
                return (int) $found;
            }
        }
        return null;
    }
}
