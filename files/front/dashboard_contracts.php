<?php

include ('../inc/includes.php');

// Check if user is logged in
Session::checkLoginUser();

// Definir título da página
$page_title = "Dashboard de Contratos";

// Adicionar estilos específicos
Html::header($page_title, $_SERVER['PHP_SELF'], "management", "contract");

echo '<link rel="stylesheet" href="../css/dashboard_contracts.css?v='.time().'">';

// Buscar contratos ativos
global $DB;

$query = [
    'SELECT' => [
        'glpi_contracts.id',
        'glpi_contracts.name',
        'glpi_contracts.begin_date',
        'glpi_contracts.duration',
        'glpi_contracts.comment',
        'glpi_contracttypes.name AS type_name'
    ],
    'FROM' => 'glpi_contracts',
    'LEFT JOIN' => [
        'glpi_contracttypes' => [
            'ON' => [
                'glpi_contracts' => 'contracttypes_id',
                'glpi_contracttypes' => 'id'
            ]
        ]
    ],
    'WHERE' => [
        'glpi_contracts.is_deleted' => 0
    ],
    'ORDER' => 'glpi_contracts.begin_date DESC'
];

$iterator = $DB->request($query);

?>

<div class="dashboard-container">
    <div class="dashboard-title">
        <i class="fas fa-file-contract"></i>
        <span>Monitoramento de Vigência de Contratos</span>
    </div>

    <div class="dashboard-card">
        <table class="dashboard-table">
            <thead>
                <tr>
                    <th>Nome do Contrato</th>
                    <th>Tipo</th>
                    <th>Início da Vigência</th>
                    <th>Fim da Vigência</th>
                    <th>Tempo Restante</th>
                    <th>Status</th>
                    <th>Observações</th>
                </tr>
            </thead>
            <tbody>
                <?php
                if (count($iterator) > 0) {
                    foreach ($iterator as $data) {
                        // Calcular data de fim
                        $beginDate = new DateTime($data['begin_date']);
                        $duration = (int)$data['duration']; // Meses
                        
                        $endDate = clone $beginDate;
                        $endDate->modify("+{$duration} months");
                        
                        // Calcular dias restantes
                        $now = new DateTime();
                        $interval = $now->diff($endDate);
                        $daysRemaining = (int)$interval->format('%r%a'); // %r inclui sinal negativo se passado
                        
                        // Determinar Status e Cor
                        $statusClass = '';
                        $statusText = '';
                        $rowClass = '';
                        
                        if ($daysRemaining < 0) {
                            $statusClass = 'status-expired';
                            $rowClass = 'tr-status-expired';
                            $statusText = 'VENCIDO';
                        } elseif ($daysRemaining <= 7) {
                            $statusClass = 'status-expired'; // Badge stays red/expired style for critical
                            $rowClass = 'tr-status-expired';
                            $statusText = 'CRÍTICO (< 7 dias)';
                        } elseif ($daysRemaining <= 30) {
                            $statusClass = 'status-critical';
                            $rowClass = 'tr-status-critical';
                            $statusText = 'ATENÇÃO (< 30 dias)';
                        } elseif ($daysRemaining <= 90) {
                            $statusClass = 'status-warning';
                            $rowClass = 'tr-status-warning';
                            $statusText = 'ALERTA (< 90 dias)';
                        } else {
                            $statusClass = 'status-good';
                            $rowClass = 'tr-status-good';
                            $statusText = 'VIGENTE';
                        }
                        
                        // Formatar datas
                        $beginDateFmt = $beginDate->format('d/m/Y');
                        $endDateFmt = $endDate->format('d/m/Y');
                        
                        // Link para o contrato
                        $link = Contract::getFormURLWithID($data['id']);
                        
                        echo "<tr class='{$rowClass}'>";
                        echo "<td><a href='{$link}' class='contract-link'>{$data['name']}</a></td>";
                        echo "<td>" . ($data['type_name'] ?? '-') . "</td>";
                        echo "<td>{$beginDateFmt}</td>";
                        echo "<td>{$endDateFmt}</td>";
                        
                        // Tempo restante amigável
                        $timeLeft = '';
                        if ($daysRemaining < 0) {
                            $timeLeft = abs($daysRemaining) . " dias atrás";
                        } else {
                            $timeLeft = $daysRemaining . " dias";
                        }
                        
                        echo "<td>{$timeLeft}</td>";
                        echo "<td><span class='badge-status {$statusClass}'>{$statusText}</span></td>";
                        echo "<td><div class='desc-text' title='" . htmlspecialchars($data['comment']) . "'>" . ($data['comment'] ?? '-') . "</div></td>";
                        echo "</tr>";
                    }
                } else {
                    echo "<tr><td colspan='7' style='text-align:center;'>Nenhum contrato encontrado.</td></tr>";
                }
                ?>
            </tbody>
        </table>
    </div>
    
    <div style="margin-top: 20px; font-size: 12px; color: #7f8c8d; text-align: center;">
        <p>Legenda: 
            <span class="badge-status status-good">Vigente (>90 dias)</span>
            <span class="badge-status status-warning">Alerta (30-90 dias)</span>
            <span class="badge-status status-critical">Atenção (7-30 dias)</span>
            <span class="badge-status status-expired">Crítico/Vencido (<7 dias)</span>
        </p>
    </div>
</div>

<?php
Html::footer();
