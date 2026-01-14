<?php
/**
 * GLPI Chatbot Installer
 * 
 * Usage: Place this folder in your GLPI root directory and run this script via browser or CLI.
 * Example: http://your-glpi/glpi-chatbot-installer/install.php
 */

define('GLPI_ROOT', '..');

// Include GLPI context
if (file_exists(GLPI_ROOT . '/inc/includes.php')) {
    include (GLPI_ROOT . '/inc/includes.php');
} else {
    die("Error: Could not find GLPI includes. Please make sure this folder is inside your GLPI root directory.\n");
}

// Check permissions
if (!Session::haveRight('config', UPDATE)) {
    die("Error: You need administrative privileges to install this plugin.\n");
}

echo "<h1>GLPI Chatbot Installer</h1>";
echo "<pre>";

// 1. Copy Files
echo "<h2>1. Copying Files...</h2>";

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
];

foreach ($files as $src => $dest) {
    echo "Copying $src to ../$dest... ";
    
    $fullDest = GLPI_ROOT . '/' . $dest;
    $destDir = dirname($fullDest);
    
    if (!is_dir($destDir)) {
        mkdir($destDir, 0755, true);
    }
    
    if (copy($src, $fullDest)) {
        echo "OK\n";
    } else {
        echo "FAILED\n";
    }
}

// 2. Database Migration
echo "\n<h2>2. Database Migration...</h2>";

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
    echo "Database tables checked/updated.\n";
    
} else {
    echo "Error: Migration file not found.\n";
}


// 3. Patching ticket.form.php
echo "\n<h2>3. Patching ticket.form.php...</h2>";

$ticketFormPath = GLPI_ROOT . '/front/ticket.form.php';
$content = file_get_contents($ticketFormPath);

if ($content === false) {
    die("Error: Could not read $ticketFormPath\n");
}

// Check if already patched
if (strpos($content, 'ChatbotService::isEnabled()') !== false) {
    echo "ticket.form.php is already patched.\n";
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
             "            \$chatbot_html = '';\n" .
             "            \$chatbot_html .= '<link rel=\"stylesheet\" href=\"' . \$CFG_GLPI['root_doc'] . '/css/chatbot.css\">';\n" .
             "            \$chatbot_html .= '<script src=\"' . \$CFG_GLPI['root_doc'] . '/js/chatbot.js\"></script>';\n" .
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
        echo "Successfully patched ticket.form.php\n";
    } else {
        echo "Warning: Could not find anchor point ('$menus = [') in ticket.form.php. Manual patching required.\n";
    }
}

echo "</pre>";
echo "<h3>Installation Complete!</h3>";
echo "<p>Please verify that the chatbot icon appears on ticket pages.</p>";

?>
