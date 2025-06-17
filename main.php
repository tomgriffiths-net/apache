<?php
class apache{
    public static function init():void{
        $servers = settings::read("servers");
        if(!is_array($servers)){
            mklog(0,'Failed to read servers list');
        }
        foreach($servers as $serverNumber => $serverData){
            if(isset($serverData['autostart'])){
                if($serverData['autostart']){
                    self::start($serverNumber);
                }
            }
            else{
                settings::set("servers/" . $serverNumber . "/autostart",false);
            }
        }
    }
    public static function command($line):void{
        $lines = explode(" ",$line);
        if($lines[0] === "server"){
            if($lines[1] === "create"){
                self::new_server($lines[3],$lines[4],$lines[2]);
            }
        }
        else{
            echo "Unknown command\nUsage: server create [httpdPath] [documentDirectory] [name]";
        }
    }
    public static function set_document_directory(string $httpd_conf, string $directory):bool{
        files::ensureFolder($directory);
        if(!is_file($httpd_conf)){
            mklog(2,'Config file not found: ' . $httpd_conf);
            return false;
        }
        if(!files::copyFile($httpd_conf,$httpd_conf . "_backup")){
            mklog(2,'Failed to create backup of config file ' . $httpd_conf);
            return false;
        }
        $lines = file($httpd_conf);
        if(!is_array($lines) || empty($lines)){
            mklog(2,'Failed to read config file ' . $httpd_conf);
            return false;
        }

        $oldDocumentRoot = "";
        $documentRootLine = false;
        $directorySettingsLine = false;
        foreach($lines as $lineNumber => $line){
            $line = trim($line);
            if(substr($line,0,12) === "DocumentRoot"){
                $documentRootLine = $lineNumber;
                $oldDocumentRoot = substr($line,14,-1);
            }
            elseif($line === "<Directory \"" . $oldDocumentRoot . "\">"){
                $directorySettingsLine = $lineNumber;
            }
        }

        if($documentRootLine !== false && $directorySettingsLine !== false){
            $lines[$documentRootLine] = "DocumentRoot \"" . $directory . "\"\n";
            $lines[$directorySettingsLine] = "<Directory \"" . $directory . "\">\n";
            if(file_put_contents($httpd_conf,$lines)){
                return true;
            }
            else{
                mklog(2,'Failed to save config file ' . $httpd_conf);
                return false;
            }
        }
        else{
            mklog(2,'Failed to locate DocumentRoot setting in file ' . $httpd_conf);
            return false;
        }
    }
    public static function start(int $serverNumber = 1):bool{
        $serverInfo = settings::read("servers/" . $serverNumber);
        if(is_array($serverInfo) && isset($serverInfo['serviceName'])){
            return service_manager::start_service($serverInfo['serviceName']);
        }
        else{
            mklog(2,'Failed to read settings for server ' . $serverNumber);
        }

        return false;
    }
    public static function stop(int $serverNumber = 1):bool{
        $serverInfo = settings::read("servers/" . $serverNumber);
        if(is_array($serverInfo) && isset($serverInfo['serviceName'])){
            return service_manager::stop_service($serverInfo['serviceName']);
        }
        else{
            mklog(2,'Failed to read settings for server ' . $serverNumber);
        }

        return false;
    }
    public static function new_server(string $httpdPath, string $documentDirectory, string $name):int|bool{
        $httpdPath = str_replace("/","\\",$httpdPath);
        $documentDirectory = str_replace("/","\\",$documentDirectory);

        if(!is_admin::check()){
            mklog(2,'Admin permission not present');
            return false;
        }
        if(!is_file($httpdPath)){
            mklog(2,'httpd.exe not found');
            return false;
        }

        $serverNumber = 1;
        while(settings::isset("servers/" . $serverNumber)){
            $serverNumber ++;
        }

        $documentDirectory = str_replace("<serverNumber>",$serverNumber,$documentDirectory);
        $serverInfo = array(
            "serviceName"                => "php_cli_apache_server_" . $serverNumber,
            "name"                       => $name,
            "specifiedDocumentDirectory" => $documentDirectory,
            "executable"                 => $httpdPath
        );
        if(!settings::set("servers/" . $serverNumber,$serverInfo)){
            mklog(2,'Failed to save server information');
            return false;
        }

        $httpd_conf = files::getFileDir(files::getFileDir($httpdPath)) . "/conf/httpd.conf";
        if(!is_file($httpd_conf)){
            mklog(2,'Failed to locate httpd.conf');
            return false;
        }
        if(!self::set_document_directory($httpd_conf,$documentDirectory)){
            mklog(2,'Failed to set document directory');
            return false;
        }

        exec('"' . $httpdPath . '" -k install -n "' . $serverInfo['serviceName'] . '" >nul 2>&1', $output, $exitCode);

        if($exitCode === 0){
            mklog(1,'Created server ' . $serverNumber);
            return $serverNumber;
        }
        else{
            mklog(2,'Failed to install apache service');
            return false;
        }
    }
    public static function delete_server(int $serverNumber):bool{
        $serverInfo = settings::read("servers/" . $serverNumber);
        if(!is_array($serverInfo) || !isset($serverInfo['serviceName'])){
            mklog(2,'Failed to read settings for server ' . $serverNumber);
            return false;
        }

        if(!service_manager::stop_service($serverInfo['serviceName'])){
            mklog(2,'Failed to stop service');
            return false;
        }

        if(!settings::unset("servers/" . $serverNumber)){
            mklog(2,'Failed to delete settings');
            return false;
        }

        if(!service_manager::delete_service($serverInfo['serviceName'])){
            mklog(2,'Failed to delete service');
            return false;
        }
        return true;
    }
    public static function set_autostart(int $serverNumber, bool $autostart):bool{
        if(!settings::isset("servers/" . $serverNumber)){
            mklog(2,'Failed to read server information');
            return false;
        }

        if(!settings::set("servers/" . $serverNumber . "/autostart", $autostart)){
            mklog(2,'Failed to save server information');
            return false;
        }

        return true;
    }
    public static function ensurePhpExtension(int $serverNumber, array $extensions):bool{
        $info = settings::read('servers/' . $serverNumber);

        if(!is_array($info)){
            mklog(2,'Failed to read server information');
            return false;
        }

        $phpIni = files::getFileDir(files::getFileDir(files::getFileDir($info['executable']))) . "\\php\\php.ini";
        if(!is_file($phpIni)){
            mklog(2,'Failed to find php.ini');
            return false;
        }

        $file = file($phpIni);
        if(!is_array($file)){
            mklog(2,'Failed to read php.ini');
            return false;
        }

        $enabledExtensions = [];

        foreach($file as $index => $line){
            $line1 = substr(trim($line),0,strpos($line . " "," "));
            $substr = substr($line1,0,10);
            if($substr === ";extension" || $substr === "extension="){
                foreach($extensions as $extension){
                    $line2 = "extension=" . $extension;
                    if($line1 === $line2){
                        $enabledExtensions[] = $extension;
                    }
                    elseif($line1 === ";" . $line2){
                        $file[$index] = str_replace(";","",$line);
                        $enabledExtensions[] = $extension;
                    }
                }
            }
        }
        foreach($extensions as $extension){
            if(!in_array($extension,$enabledExtensions)){
                mklog(2,"Unable to find extension entry '" . $extension . "' in " . $phpIni);
                return false;
            }
        }

        if(!file_put_contents($phpIni,$file)){
            mklog(2,'Failed to save php.ini');
            return false;
        }
        
        return true;
    }
}