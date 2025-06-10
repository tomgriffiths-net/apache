<?php
class apache{
    public static function init():void{
        $servers = settings::read("servers");
        if(!is_array($servers)){
            mklog(0,'Failed to read servers list');
        }
        foreach($servers as $serverNumber => $serverData){
            if(isset($serverData['autostart'])){
                if($serverData['autostart'] === true){
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
    }
    public static function set_document_directory($httpd_conf,$directory){
        files::ensureFolder($directory);
        files::copyFile($httpd_conf,$httpd_conf . "_backup");
        $lines = file($httpd_conf);
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
            file_put_contents($httpd_conf,$lines);
        }
    }
    public static function start(int $serverNumber = 1){
        if(is_admin::check()){
            $serverInfo = settings::read("servers/" . $serverNumber);
            return service_manager::start_service($serverInfo['serviceName']);
        }
        return false;
    }
    public static function stop(int $serverNumber = 1){
        if(is_admin::check()){
            $serverInfo = settings::read("servers/" . $serverNumber);
            return service_manager::stop_service($serverInfo['serviceName']);
        }
        return false;
    }
    public static function new_server($httpdPath,$documentDirectory,$name):int|bool{
        $httpdPath = str_replace("/","\\",$httpdPath);
        $documentDirectory = str_replace("/","\\",$documentDirectory);
        if(is_file($httpdPath) && is_admin::check()){
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
            settings::set("servers/" . $serverNumber,$serverInfo);
            $httpd_conf = files::getFileDir($httpdPath) . "/../conf/httpd.conf";
            if(is_file($httpd_conf)){
                self::set_document_directory($httpd_conf,$documentDirectory);
            }
            else{
                files::mkFile($httpd_conf,"DocumentRoot \"" . str_replace("\\","\\\\",$documentDirectory) . "\"");
            }
            exec($httpdPath . ' -k install -n "' . $serverInfo['serviceName'] . '" >nul 2>&1');
            return $serverNumber;
        }
        else{
            mklog('warning','httpd.exe not found or not admin',false);
        }
        return false;
    }
    public static function delete_server(int $serverNumber):bool{
        if(is_admin::check()){
            $serverInfo = settings::read("servers/" . $serverNumber);
            service_manager::stop_service($serverInfo['serviceName']);
            settings::unset("servers/" . $serverNumber);
            return cmd::run("sc delete " . $serverInfo['serviceName'],true,false);
        }
        else{
            mklog('warning','Unable to delete apache server ' . $serverNumber . ', administrator permissions required',false);
        }
        return false;
    }
    public static function set_autostart(int $serverNumber, bool $autostart){
        if(settings::isset("servers/" . $serverNumber)){
            settings::set("servers/" . $serverNumber . "/autostart",$autostart);
        }
    }
    public static function ensurePhpExtension(int $serverNumber, array $extensions):bool{
        $info = settings::read('servers/' . $serverNumber);
        $enabledExtensions = array();
        if(is_array($info)){
            $phpIni = files::getFileDir(files::getFileDir(files::getFileDir($info['executable']))) . "\\php\\php.ini";
            if(is_file($phpIni)){
                $file = file($phpIni);
                if(is_array($file)){
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
                            mklog('warning',"Unable to find extension entry '" . $extension . "' in " . $phpIni);
                            return false;
                        }
                    }
                    if(file_put_contents($phpIni,$file) !== false){
                        return true;
                    }
                }
            }
        }
        return false;
    }
}