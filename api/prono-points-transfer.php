<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require_once __DIR__.'/prono-core.php';

require_method('POST');
p50_prono_ensure_schema();

$sender = auth_user();
$input = json_input();
$senderId = (string)$sender['id'];
$recipientId = trim((string)($input['recipientId'] ?? ''));
$amountRaw = $input['amount'] ?? null;

if ($recipientId === '' || !is_numeric($amountRaw)) {
    json_response(['error' => 'Destinataire et montant requis.'], 400);
}
$amount = (int)$amountRaw;
if ((float)$amountRaw !== (float)$amount || $amount < 1 || $amount > 1500) {
    json_response(['error' => 'Le montant doit être compris entre 1 et 1 500 points.'], 422);
}
if ($recipientId === $senderId) {
    json_response(['error' => 'Tu ne peux pas t’offrir des points à toi-même.'], 409);
}

$pdo = db();
$senderPseudo = ltrim(trim((string)($sender['display_name'] ?? '')), '@');
if ($senderPseudo === '') $senderPseudo = 'Un membre PASS50';

$recipientStmt = $pdo->prepare("SELECT id,display_name FROM users WHERE id=? AND deleted_at IS NULL LIMIT 1");
$recipientStmt->execute([$recipientId]);
$recipient = $recipientStmt->fetch();
if (!$recipient) {
    json_response(['error' => 'Utilisateur introuvable.'], 404);
}

p50_prono_ensure_balance($pdo, $senderId);
p50_prono_ensure_balance($pdo, $recipientId);

$pdo->beginTransaction();
try {
    $ids = [$senderId, $recipientId];
    sort($ids, SORT_STRING);
    $lock = $pdo->prepare('SELECT user_id,balance FROM p50_prono_balances WHERE user_id IN (?,?) ORDER BY user_id FOR UPDATE');
    $lock->execute($ids);
    $balances = [];
    foreach ($lock->fetchAll() ?: [] as $row) {
        $balances[(string)$row['user_id']] = (float)$row['balance'];
    }

    $senderBalance = (float)($balances[$senderId] ?? 0);
    if (($senderBalance - $amount) < P50_PRONO_BALANCE_FLOOR) {
        throw new DomainException('Solde insuffisant : tu dois conserver au moins '.P50_PRONO_BALANCE_FLOOR.' points.');
    }

    $pdo->prepare('UPDATE p50_prono_balances SET balance=balance-? WHERE user_id=?')->execute([$amount, $senderId]);
    $pdo->prepare('UPDATE p50_prono_balances SET balance=balance+? WHERE user_id=?')->execute([$amount, $recipientId]);

    $transferId = p50_prono_uuid();
    $ledger = $pdo->prepare('INSERT INTO p50_prono_points_ledger(id,user_id,delta,reason,ref_id) VALUES(?,?,?,?,?)');
    $ledger->execute([p50_prono_uuid(), $senderId, -$amount, 'points_gift_sent', $transferId]);
    $ledger->execute([p50_prono_uuid(), $recipientId, $amount, 'points_gift_received', $transferId]);

    $notificationTitle = 'Tu as reçu '.number_format($amount, 0, ',', ' ').' points 🎁';
    $notificationBody = '@'.$senderPseudo.' t’a offert '.number_format($amount, 0, ',', ' ').' points dans Pronostics.';
    p50_notification_create($pdo, $recipientId, $notificationTitle, $notificationBody, 'points_received', '/pronostics.html?dashboard=points');

    $pdo->commit();
} catch (DomainException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_response(['error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('PASS50 prono-points-transfer: '.$e->getMessage());
    json_response(['error' => 'Transfert impossible pour le moment.'], 500);
}

json_response([
    'ok' => true,
    'recipient' => ['id' => $recipientId, 'pseudo' => (string)$recipient['display_name']],
    'amount' => $amount,
    'balance' => p50_prono_balance($pdo, $senderId),
]);
