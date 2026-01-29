<?php
// Suppress any PHP warnings/errors that might break JSON output
error_reporting(0);

function handleMetaQuestion($question, $db) {
    $questionLower = strtolower(trim($question));
    
    // Remove question marks and extra spaces
    $questionLower = preg_replace('/[?!.]+/', '', $questionLower);
    $questionLower = preg_replace('/\s+/', ' ', $questionLower);
    
    if (preg_match('/\b(password|passwd|pwd|credential|secret|key|token|auth|login)\b/i', $questionLower)) {
        return [
            "is_meta" => true,
            "error" => "Security Restriction",
            "details" => "Cannot provide passwords, credentials, or other sensitive security information. This is a protected operation for security reasons."
        ];
    }
 
    // Pattern 0: Location questions - MUST BE FIRST (very specific)
    // "where is db located", "where are tables located", "db location", "table location"
    if (preg_match('/\b(where|location|path|stored|hosted)\b/i', $questionLower)) {
        
        // Sub-pattern A: Where are TABLES located
        if (preg_match('/\b(tables?)\b/i', $questionLower)) {
            return getTableLocation($db);
        }
        
        // Sub-pattern B: Where is DATABASE located
        if (preg_match('/\b(database|db|data)\b/i', $questionLower)) {
            return getDatabaseLocation($db);
        }
    }
    
    // Pattern 1: LIST tables (NOT count - user wants to see actual table names)
    // Matches: "what are the tables", "list tables", "show tables", "tables included", "tables present"
    if (preg_match('/\b(what are|list|show|display|tell me|give me|which are).*(the )?(tables?|table names)\b/i', $questionLower) ||
        preg_match('/\b(tables?).*(included|available|present|exist|are there|in|my|current|this)\b/i', $questionLower) ||
        preg_match('/\b(all |available |existing )?(tables?)\s+(in|on|for|present)\b/i', $questionLower)) {
        
        // Make sure it's NOT asking for COUNT specifically
        if (!preg_match('/\b(how many|count|number of|total|how much)\b/i', $questionLower)) {
            // Make sure it's NOT asking about location
            if (!preg_match('/\b(where|location|path|stored|hosted)\b/i', $questionLower)) {
                // Make sure it's asking for table list, not data FROM a specific table
                if (!preg_match('/\bfrom\s+[\w\.]+/i', $questionLower)) {
                    return listAllTables($db);
                }
            }
        }
    }
    
    // Pattern 2: COUNT tables (user specifically wants the number)
    // Matches: "how many tables", "count tables", "number of tables", "total tables"
    if (preg_match('/\b(how many|count|number of|total|how much)\b/i', $questionLower) &&
        preg_match('/\b(tables?)\b/i', $questionLower)) {
        return getTableCount($db);
    }
    
    // Pattern 3: Database statistics (THIRD - asking for comprehensive info)
    if (preg_match('/\b(database|db).*(size|statistics|stats|info|details)\b/i', $questionLower)) {
        return getDatabaseStatistics($db);
    }
    
    // Pattern 4: Server information (FOURTH)
    if (preg_match('/\b(server|sql server).*(name|version|info|details)\b/i', $questionLower) ||
        preg_match('/\bwhat.*(server)\b/i', $questionLower)) {
        return getServerInfo($db);
    }
    
    // Pattern 5: Connection details (FIFTH)
    if (preg_match('/\b(connection|connected to).*(details|info|information)\b/i', $questionLower)) {
        return getConnectionDetails($db);
    }
    
    // Pattern 6: Database name only (LAST - least specific, catches remaining DB questions)
    // Only trigger if NOT asking about tables, counts, or lists
    if (preg_match('/\b(what|which|tell me|show me|give me).*(database|db).*(name|called)\b/i', $questionLower) ||
        preg_match('/\b(name).*(database|db)\b/i', $questionLower) ||
        preg_match('/\b(current|present|connected|my|this).*(database|db)\b/i', $questionLower) ||
        preg_match('/\bwhat.*(database|db)\b/i', $questionLower) ||
        preg_match('/\bwhich.*(database|db)\b/i', $questionLower)) {
        
        // Exclude if asking about tables, counts, locations, or other specific info
        if (!preg_match('/\b(table|tables|count|how many|list|show tables|statistics|stats|where|location)\b/i', $questionLower)) {
            return getCurrentDatabaseInfo($db);
        }
    }
    
    return null; // Not a meta question
}

function getCurrentDatabaseInfo($db) {
    try {
        $stmt = $db->query("SELECT DB_NAME() AS CurrentDatabase");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $dbName = $result['CurrentDatabase'];
        
        // Get server name
        $stmt = $db->query("SELECT @@SERVERNAME AS ServerName");
        $server = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $answer = "You are currently connected to database: **{$dbName}**\n\n";
        $answer .= "Server: {$server['ServerName']}";
        
        return [
            "is_meta" => true,
            "answer" => $answer,
            "data" => [
                [
                    "database" => $dbName,
                    "server" => $server['ServerName']
                ]
            ]
        ];
    } catch (Exception $e) {
        return [
            "is_meta" => true,
            "error" => "Could not retrieve database information",
            "details" => $e->getMessage()
        ];
    }
}

function getConnectionDetails($db) {
    try {
        $stmt = $db->query("
            SELECT 
                DB_NAME() AS DatabaseName,
                @@SERVERNAME AS ServerName,
                SUSER_SNAME() AS LoginName,
                USER_NAME() AS UserName
        ");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $answer = "Current Connection Details:\n\n";
        $answer .= "• Database: **{$result['DatabaseName']}**\n";
        $answer .= "• Server: {$result['ServerName']}\n";
        $answer .= "• Login User: {$result['LoginName']}\n";
        $answer .= "• Database User: {$result['UserName']}";
        
        return [
            "is_meta" => true,
            "answer" => $answer,
            "data" => [$result]
        ];
    } catch (Exception $e) {
        return [
            "is_meta" => true,
            "error" => "Could not retrieve connection details",
            "details" => $e->getMessage()
        ];
    }
}

function getTableCount($db) {
    try {
        $stmt = $db->query("
            SELECT COUNT(*) AS TableCount
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_TYPE = 'BASE TABLE'
            AND TABLE_SCHEMA NOT IN ('sys', 'INFORMATION_SCHEMA')
        ");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $count = $result['TableCount'];
        $dbName = $db->query("SELECT DB_NAME() AS CurrentDatabase")->fetch(PDO::FETCH_ASSOC)['CurrentDatabase'];
        
        $answer = "The database **{$dbName}** contains **{$count} table(s)**.";
        
        return [
            "is_meta" => true,
            "answer" => $answer,
            "data" => [
                [
                    "database" => $dbName,
                    "table_count" => $count
                ]
            ]
        ];
    } catch (Exception $e) {
        return [
            "is_meta" => true,
            "error" => "Could not count tables",
            "details" => $e->getMessage()
        ];
    }
}

function listAllTables($db) {
    try {
        $stmt = $db->query("
            SELECT 
                TABLE_SCHEMA + '.' + TABLE_NAME AS FullTableName,
                TABLE_NAME AS TableName,
                TABLE_SCHEMA AS SchemaName
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_TYPE = 'BASE TABLE'
            AND TABLE_SCHEMA NOT IN ('sys', 'INFORMATION_SCHEMA')
            ORDER BY TABLE_SCHEMA, TABLE_NAME
        ");
        $tables = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $dbName = $db->query("SELECT DB_NAME() AS CurrentDatabase")->fetch(PDO::FETCH_ASSOC)['CurrentDatabase'];
        
        if (empty($tables)) {
            return [
                "is_meta" => true,
                "answer" => "No tables found in database **{$dbName}**.",
                "data" => []
            ];
        }
        
        $answer = "Tables in database **{$dbName}** (" . count($tables) . " total):\n\n";
        
        $groupedBySchema = [];
        foreach ($tables as $table) {
            $groupedBySchema[$table['SchemaName']][] = $table;
        }
        
        foreach ($groupedBySchema as $schema => $schemaTables) {
            $answer .= "**Schema: {$schema}**\n";
            foreach ($schemaTables as $table) {
                $answer .= "  • {$table['TableName']}\n";
            }
            $answer .= "\n";
        }
        
        return [
            "is_meta" => true,
            "answer" => $answer,
            "data" => $tables,
            "row_count" => count($tables)
        ];
    } catch (Exception $e) {
        return [
            "is_meta" => true,
            "error" => "Could not list tables",
            "details" => $e->getMessage()
        ];
    }
}

function getDatabaseStatistics($db) {
    try {
        $dbName = $db->query("SELECT DB_NAME() AS CurrentDatabase")->fetch(PDO::FETCH_ASSOC)['CurrentDatabase'];
        
        // Get table count
        $stmt = $db->query("
            SELECT COUNT(*) AS TableCount
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_TYPE = 'BASE TABLE'
            AND TABLE_SCHEMA NOT IN ('sys', 'INFORMATION_SCHEMA')
        ");
        $tableCount = $stmt->fetch(PDO::FETCH_ASSOC)['TableCount'];
        
        // Get view count
        $stmt = $db->query("
            SELECT COUNT(*) AS ViewCount
            FROM INFORMATION_SCHEMA.VIEWS
            WHERE TABLE_SCHEMA NOT IN ('sys', 'INFORMATION_SCHEMA')
        ");
        $viewCount = $stmt->fetch(PDO::FETCH_ASSOC)['ViewCount'];
        
        // Get stored procedure count
        $stmt = $db->query("
            SELECT COUNT(*) AS ProcCount
            FROM INFORMATION_SCHEMA.ROUTINES
            WHERE ROUTINE_TYPE = 'PROCEDURE'
            AND ROUTINE_SCHEMA NOT IN ('sys', 'INFORMATION_SCHEMA')
        ");
        $procCount = $stmt->fetch(PDO::FETCH_ASSOC)['ProcCount'];
        
        $answer = "Database Statistics for **{$dbName}**:\n\n";
        $answer .= "• Tables: {$tableCount}\n";
        $answer .= "• Views: {$viewCount}\n";
        $answer .= "• Stored Procedures: {$procCount}";
        
        return [
            "is_meta" => true,
            "answer" => $answer,
            "data" => [
                [
                    "database" => $dbName,
                    "tables" => $tableCount,
                    "views" => $viewCount,
                    "procedures" => $procCount
                ]
            ]
        ];
    } catch (Exception $e) {
        return [
            "is_meta" => true,
            "error" => "Could not retrieve statistics",
            "details" => $e->getMessage()
        ];
    }
}

function getServerInfo($db) {
    try {
        $stmt = $db->query("SELECT @@VERSION AS Version");
        $version = $stmt->fetch(PDO::FETCH_ASSOC)['Version'];
        
        // Extract just the first line (version info)
        $versionLines = explode("\n", $version);
        $shortVersion = trim($versionLines[0]);
        
        $stmt = $db->query("SELECT @@SERVERNAME AS ServerName");
        $server = $stmt->fetch(PDO::FETCH_ASSOC)['ServerName'];
        
        $answer = "SQL Server Information:\n\n";
        $answer .= "• Server Name: {$server}\n";
        $answer .= "• Version: {$shortVersion}";
        
        return [
            "is_meta" => true,
            "answer" => $answer,
            "data" => [
                [
                    "server_name" => $server,
                    "version" => $shortVersion,
                    "full_version" => $version
                ]
            ]
        ];
    } catch (Exception $e) {
        return [
            "is_meta" => true,
            "error" => "Could not retrieve server information",
            "details" => $e->getMessage()
        ];
    }
}

function getTableLocation($db) {
    try {
        $stmt = $db->query("SELECT DB_NAME() AS CurrentDatabase");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result) {
            throw new Exception("Could not get database name");
        }
        
        $dbName = $result['CurrentDatabase'];
        
        $stmt = $db->query("SELECT @@SERVERNAME AS ServerName");
        $serverResult = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$serverResult) {
            throw new Exception("Could not get server name");
        }
        
        $server = $serverResult['ServerName'];
        
        $answer = "All tables are located in database: **{$dbName}**\n\n";
        $answer .= "Server: {$server}\n";
        $answer .= "Management Tool: **SQL Server Management Studio (SSMS)**";
        
        return [
            "is_meta" => true,
            "answer" => $answer,
            "data" => [
                [
                    "database" => $dbName,
                    "server" => $server,
                    "management_tool" => "SQL Server Management Studio (SSMS)",
                    "location_type" => "tables"
                ]
            ]
        ];
    } catch (Exception $e) {
        return [
            "is_meta" => true,
            "error" => "Could not retrieve table location",
            "details" => $e->getMessage()
        ];
    }
}

function getDatabaseLocation($db) {
    try {
        $stmt = $db->query("SELECT DB_NAME() AS CurrentDatabase");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result) {
            throw new Exception("Could not get database name");
        }
        
        $dbName = $result['CurrentDatabase'];
        
        $stmt = $db->query("SELECT @@SERVERNAME AS ServerName");
        $serverResult = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$serverResult) {
            throw new Exception("Could not get server name");
        }
        
        $server = $serverResult['ServerName'];
        
        // SECURITY: Do NOT reveal database file paths
        // This is sensitive information that could be used for attacks
        
        $answer = "Database Location Information:\n\n";
        $answer .= "• Database Name: **{$dbName}**\n";
        $answer .= "• Server: {$server}\n";
        $answer .= "• Database System: **Microsoft SQL Server**\n";
        $answer .= "• Management Tool: **SQL Server Management Studio (SSMS)**\n\n";
        $answer .= "_Note: Database file path information is restricted for security purposes._";
        
        return [
            "is_meta" => true,
            "answer" => $answer,
            "data" => [
                [
                    "database" => $dbName,
                    "server" => $server,
                    "database_system" => "Microsoft SQL Server",
                    "management_tool" => "SQL Server Management Studio (SSMS)"
                ]
            ]
        ];
    } catch (Exception $e) {
        return [
            "is_meta" => true,
            "error" => "Could not retrieve database location",
            "details" => $e->getMessage()
        ];
    }
}