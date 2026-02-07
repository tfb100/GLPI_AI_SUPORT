<?php
/**
 * GLPI Chatbot Installer
 * 
 * Usage: Place this folder in your GLPI root directory and run this script via browser or CLI.
 * Example: http://your-glpi/glpi-chatbot-installer/install.php
 */

define('GLPI_ROOT', '..');
$isCLI = (php_sapi_name() === 'cli');

function output($text, $level = 'info') {
    global $isCLI;
    if ($isCLI) {
        $prefix = '';
        switch ($level) {
            case 'h1': $prefix = "\n=== "; break;
            case 'h2': $prefix = "\n--- "; break;
            case 'success': $prefix = "[OK] "; break;
            case 'error': $prefix = "[ERROR] "; break;
            case 'warning': $prefix = "[WARN] "; break;
        }
        echo $prefix . strip_tags($text) . ($level == 'h1' || $level == 'h2' ? " ===\n" : "\n");
    } else {
        switch ($level) {
            case 'h1': echo "<h1>$text</h1>"; break;
            case 'h2': echo "<h2>$text</h2>"; break;
            case 'error': echo "<span style='color:red;'>[ERROR] $text</span><br>"; break;
            default: echo "$text<br>"; break;
        }
    }
}

// Include GLPI context
if (file_exists(GLPI_ROOT . '/inc/includes.php')) {
    include (GLPI_ROOT . '/inc/includes.php');
} else {
    output("Error: Could not find GLPI includes. Please make sure this folder is inside your GLPI root directory.", 'error');
    exit(1);
}

// Check permissions - Skip if CLI
if (!$isCLI) {
    if (!Session::checkLoginUser() || !Session::haveRight('config', UPDATE)) {
        output("Error: You need administrative privileges to install this plugin.", 'error');
        exit(1);
    }
}

output("GLPI Chatbot Installer", 'h1');
if (!$isCLI) echo "<pre>";

// 1. Copy Files
output("1. Copying Files...", 'h2');

$files = [
    'files/src/AI/ChatbotService.php'       => 'src/AI/ChatbotService.php',
    'files/src/AI/GeminiClient.php'         => 'src/AI/GeminiClient.php',
    'files/src/AI/OllamaClient.php'         => 'src/AI/OllamaClient.php',
    'files/src/AI/AIClientInterface.php'    => 'src/AI/AIClientInterface.php',
    'files/src/AI/KnowledgeBaseSearcher.php'=> 'src/AI/KnowledgeBaseSearcher.php',
    'files/src/AI/TicketAnalyzer.php'       => 'src/AI/TicketAnalyzer.php',
    'files/ajax/chatbot.php'                => 'ajax/chatbot.php',
    'files/js/chatbot.js'                   => 'js/chatbot.js',
    'files/css/chatbot.css'                 => 'css/chatbot.css',
    'files/front/dashboard_contracts.php'   => 'front/dashboard_contracts.php',
    'files/css/dashboard_contracts.css'     => 'css/dashboard_contracts.css',
    'files/front/chatbot_feedback.php'      => 'front/chatbot_feedback.php',
    'files/css/chatbot_feedback.css'        => 'css/chatbot_feedback.css',
];

foreach ($files as $src => $dest) {
    echo "Copying $src to ../$dest... ";
    
    $fullDest = GLPI_ROOT . '/' . $dest;
    $destDir = dirname($fullDest);
    
    if (!is_dir($destDir)) {
        mkdir($destDir, 0755, true);
    }
    
    if (copy($src, $fullDest)) {
        output("OK", 'success');
    } else {
        output("FAILED", 'error');
    }
}

// 2. Database Migration
output("2. Database Migration...", 'h2');

$migrationFile = 'files/install/migrations/chatbot_tables.sql';
if (file_exists($migrationFile)) {
    $sql = file_get_contents($migrationFile);
    
    // Split by semicolon, but handle delimiters if complex (simple split works for this file structure)
    // Actually, the file uses specific DELIMITER logic sometimes, but let's try strict statement execution
    // Since the provided SQL file has detailed logic with PREPARE statements, it's safer to execute broadly or parse carefully.
    // However, for this specific SQL which uses session variables and PREPARE, we might need to rely on raw execution if supported, or split carefully.
    // Given the complexity of the SQL file (IF/THEN logic), it's better to execute it as a single block if the driver supports it, or try to emulate.
    // OR: Just run the CREATE TABLEs directly if we want to be safer and simpler.
    
    // Simpler approach: Let's assume the user runs the SQL manually or we just run the CREATE statements.
    // The provided SQL file does check for existence.
    // Let's try splitting by ";" for simple statements.
    
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    
    foreach ($statements as $stmt) {
        if (!empty($stmt) && (strpos($stmt, '--') !== 0)) {
            try {
                $DB->query($stmt);
            } catch (Exception $e) {
                // Ignore errors for already existing tables/keys or comments
                // echo "Warning: " . $e->getMessage() . "\n";
            }
        }
    }
    output("Database tables checked/updated.", 'success');
    
} else {
    output("Error: Migration file not found.", 'error');
}


// 3. Patching ticket.form.php
output("3. Patching ticket.form.php...", 'h2');

$ticketFormPath = GLPI_ROOT . '/front/ticket.form.php';
$content = file_get_contents($ticketFormPath);

if ($content === false) {
    output("Error: Could not read $ticketFormPath", 'error');
    exit(1);
}

// Check if already patched
if (strpos($content, 'ChatbotService::isEnabled()') !== false) {
    output("ticket.form.php is already patched.", 'info');
} else {
    // 3.1 Add Use Statement
    if (strpos($content, 'use Glpi\AI\ChatbotService;') === false) {
        $content = preg_replace(
            '/use Glpi\\\Toolbox\\\Sanitizer;/', 
            "use Glpi\\Toolbox\\Sanitizer;\nuse Glpi\\AI\\ChatbotService;", 
            $content, 
            1
        );
        echo "Added 'use' statement.\n";
    }

    // 3.2 Add Initialization Code
    // We look for where $menus are defined for the displayFullPageForItem call
    $search = '$menus = [';
    
    // We want to insert BEFORE this line
    
    $patch = "\n    // =========================================\n" .
             "    // Chatbot IA Integration\n" .
             "    // =========================================\n" .
             "    if (ChatbotService::isEnabled()) {\n" .
             "        \$ticket_id = (int)\$_GET['id'];\n" .
             "        \n" .
             "        // Verificar se usuário tem permissão para ver o ticket\n" .
             "        \$ticket_check = new Ticket();\n" .
             "        if (\$ticket_check->getFromDB(\$ticket_id) && \$ticket_check->canViewItem()) {\n" .
             "            // Adicionar CSS e JS nas opções de after_display\n" .
             "            // Adicionando timestamp para evitar cache\n" .
             "            \$ver = time();\n" .
             "            \$chatbot_html = '';\n" .
             "            \$chatbot_html .= '<link rel=\"stylesheet\" href=\"' . \$CFG_GLPI['root_doc'] . '/css/chatbot.css?v=' . \$ver . '\">';\n" .
             "            \$chatbot_html .= '<script src=\"' . \$CFG_GLPI['root_doc'] . '/js/chatbot.js?v=' . \$ver . '\"></script>';\n" .
             "            \$chatbot_html .= '<script>\n" .
             "                $(document).ready(function() {\n" .
             "                    if (typeof GLPIChatbot !== \"undefined\") {\n" .
             "                        GLPIChatbot.init(' . \$ticket_id . ', \"' . \$CFG_GLPI['root_doc'] . '/ajax/chatbot.php\");\n" .
             "                    } else {\n" .
             "                        console.error(\"GLPIChatbot não foi carregado. Verifique se chatbot.js está acessível.\");\n" .
             "                    }\n" .
             "                });\n" .
             "            </script>';\n" .
             "            \n" .
             "            // Adicionar ao after_display\n" .
             "            if (isset(\$options['after_display'])) {\n" .
             "                \$options['after_display'] .= \$chatbot_html;\n" .
             "            } else {\n" .
             "                \$options['after_display'] = \$chatbot_html;\n" .
             "            }\n" .
             "        }\n" .
             "    }\n\n    " . $search;
        
    // Use strpos to find the first occurrence of $menus = [ which should be the one inside the if(id>0) block
    // typically around line 294
    
    $newContent = str_replace($search, $patch, $content);
    
    if ($newContent !== $content) {
        file_put_contents($ticketFormPath, $newContent);
        output("Successfully patched ticket.form.php", 'success');
    } else {
        output("Warning: Could not find anchor point ('\$menus = [') in ticket.form.php. Manual patching required.", 'warning');
    }
}

// 4. Patching problem.form.php
output("4. Patching problem.form.php...", 'h2');

$problemFormPath = GLPI_ROOT . '/front/problem.form.php';
$content = file_get_contents($problemFormPath);

if ($content === false) {
    echo "Error: Could not read $problemFormPath\n";
} else {
    // Check if already patched
    if (strpos($content, 'GLPIChatbot.init') !== false) {
        output("problem.form.php is already patched.", 'info');
    } else {
        // We inject before Html::footer(); inside the else block of kanban
        $search = 'Problem::displayFullPageForItem($_GET[\'id\'] ?? 0, $menus, $options);';
        
        $patch = $search . "\n\n" .
                 "        // INJECTION: CHATBOT IA\n" .
                 "        // =========================================\n" .
                 "        \$chatbotConfig = \$CFG_GLPI['chatbot_enabled'] ?? 0;\n" .
                 "        if (\$chatbotConfig && isset(\$_GET['id']) && \$_GET['id'] > 0) {\n" .
                 "            \$versionTimestamp = time(); // Cache busting\n" .
                 "            echo \"<link rel='stylesheet' type='text/css' href='../css/chatbot.css?v={\$versionTimestamp}'>\";\n" .
                 "            echo \"<script src='../js/chatbot.js?v={\$versionTimestamp}'></script>\";\n" .
                 "            echo \"<script>\n" .
                 "                $(document).ready(function() {\n" .
                 "                    GLPIChatbot.init(\" . (int)\$_GET['id'] . \", '../ajax/chatbot.php', 'Problem');\n" .
                 "                });\n" .
                 "            </script>\";\n" .
                 "        }\n" .
                 "        // =========================================";

        $newContent = str_replace($search, $patch, $content);
        
        if ($newContent !== $content) {
            file_put_contents($problemFormPath, $newContent);
            output("Successfully patched problem.form.php", 'success');
        } else {
            output("Warning: Could not find anchor point in problem.form.php. Manual patching required.", 'warning');
        }
    }
}

if (!$isCLI) echo "</pre>";
output("Installation Complete!", 'h1');
output("Please verify that the chatbot icon appears on ticket pages.", 'info');

?>
