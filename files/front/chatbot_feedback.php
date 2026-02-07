<?php
/**
 * ---------------------------------------------------------------------
 *
 * GLPI - Gestionnaire Libre de Parc Informatique
 *
 * http://glpi-project.org
 *
 * @copyright 2015-2025 Teclib' and contributors.
 * @licence   https://www.gnu.org/licenses/gpl-3.0.html
 *
 * ---------------------------------------------------------------------
 */

include('../inc/includes.php');

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

Session::checkLoginUser();

// Check permissions (Admin only implies config right)
if (!Session::haveRight('config', UPDATE)) {
    Html::displayRightError();
}

Html::header('Relatório de Feedback IA', $_SERVER['PHP_SELF'], "admin", "config");

// Get statistics
global $DB;

// Total Feedback
$result = $DB->request([
    'FROM' => 'glpi_chatbot_feedback',
    'COUNT' => 'cpt'
])->current();
$totalFeedback = $result['cpt'];

// Positive Feedback
$result = $DB->request([
    'FROM' => 'glpi_chatbot_feedback',
    'COUNT' => 'cpt',
    'WHERE' => ['was_helpful' => 1]
])->current();
$positiveFeedback = $result['cpt'];

// Calculate Approval Rate
$approvalRate = $totalFeedback > 0 ? round(($positiveFeedback / $totalFeedback) * 100) : 0;

// Get Feedback List joined with Conversations
$feedbackIterator = $DB->request([
    'SELECT' => [
        'glpi_chatbot_feedback.id',
        'glpi_chatbot_feedback.was_helpful',
        'glpi_chatbot_feedback.comment',
        'glpi_chatbot_feedback.date_creation',
        'glpi_chatbot_conversations.items_id',
        'glpi_chatbot_conversations.item_type',
        'glpi_chatbot_conversations.message AS ai_response'
    ],
    'FROM' => 'glpi_chatbot_feedback',
    'INNER JOIN' => [
        'glpi_chatbot_conversations' => [
            'ON' => [
                'glpi_chatbot_feedback' => 'conversations_id',
                'glpi_chatbot_conversations' => 'id'
            ]
        ]
    ],
    'ORDER' => 'glpi_chatbot_feedback.date_creation DESC',
    'LIMIT' => 50
]);

?>

<link rel="stylesheet" type="text/css" href="../css/chatbot_feedback.css">

<div class="feedback-container">
    <a href="../front/config.php" class="back-link">&larr; Voltar para Configurações</a>

    <div class="feedback-header">
        <h1>Relatório de Qualidade da IA</h1>
        <p>Monitoramento de feedback dos usuários sobre as respostas do Chatbot.</p>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <span class="stat-value"><?php echo $totalFeedback; ?></span>
            <span class="stat-label">Total de Avaliações</span>
        </div>
        <div class="stat-card <?php echo $approvalRate >= 70 ? 'positive' : ($approvalRate >= 50 ? '' : 'negative'); ?>">
            <span class="stat-value"><?php echo $approvalRate; ?>%</span>
            <span class="stat-label">Taxa de Aprovação</span>
            <div class="progress-bar-container">
                <div class="progress-bar" style="width: <?php echo $approvalRate; ?>%; background-color: <?php echo $approvalRate >= 70 ? '#27ae60' : ($approvalRate >= 50 ? '#f1c40f' : '#c0392b'); ?>"></div>
            </div>
        </div>
    </div>

    <div class="feedback-list">
        <table class="feedback-table">
            <thead>
                <tr>
                    <th>Data</th>
                    <th>Avaliação</th>
                    <th>ID</th>
                    <th>Item do GLPI</th>
                    <th>Resposta da IA (Contexto)</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($feedbackIterator) > 0): ?>
                    <?php foreach ($feedbackIterator as $row): ?>
                        <tr>
                            <td><?php echo date('d/m/Y H:i', strtotime($row['date_creation'])); ?></td>
                            <td>
                                <?php if ($row['was_helpful']): ?>
                                    <span class="rating-badge thumbs-up"><i class="fas fa-thumbs-up"></i> Útil</span>
                                <?php else: ?>
                                    <span class="rating-badge thumbs-down"><i class="fas fa-thumbs-down"></i> Não Útil</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?php echo $row['item_type']; ?> #<?php echo $row['items_id']; ?></strong>
                            </td>
                            <td>
                                <?php
                                    $itemLink = "";
                                    if ($row['item_type'] == 'Ticket') {
                                        $ticket = new Ticket();
                                        if ($ticket->getFromDB($row['items_id'])) {
                                            $itemLink = $ticket->getLink();
                                        } else {
                                            $itemLink = "Ticket #" . $row['items_id'] . " (Deletado)";
                                        }
                                    } elseif ($row['item_type'] == 'Problem') {
                                        $problem = new Problem();
                                        if ($problem->getFromDB($row['items_id'])) {
                                            $itemLink = $problem->getLink();
                                        } else {
                                            $itemLink = "Problem #" . $row['items_id'] . " (Deletado)";
                                        }
                                    } else {
                                        $itemLink = $row['item_type'] . " #" . $row['items_id'];
                                    }
                                    echo $itemLink;
                                ?>
                            </td>
                            <td>
                                <div class="ai-message-preview" title="<?php echo htmlspecialchars($row['ai_response']); ?>">
                                    <?php echo htmlspecialchars(substr($row['ai_response'], 0, 100)) . (strlen($row['ai_response']) > 100 ? '...' : ''); ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" style="text-align:center; padding: 20px;">Nenhum feedback registrado ainda.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
Html::footer();
?>
