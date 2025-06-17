# apache
apache is a PHP-CLI package that manages apache servers, this is not apache, just a management tool for apache servers.

# Commands
- **server create [httpdPath] [documentDirectory] [name]**: Creates a server with the specified settings.

# Functions
- **init**: Starts any servers with the autostart setting enabled.
- **set_document_directory(string $httpd_conf, string $directory):bool**: Sets the document directory for a given https.conf file. Returns true on success or false on failure.
- **start(int $serverNumber=1):bool**: Starts a specified server. Returns true on success or false on failure.
- **stop(int $serverNumber=1):bool**: Stops a specified server. Returns true on success or false on failure.
- **new_server(string $httpdPath, string $documentDirectory, string $name):int|bool**: Configures and installs a new apache server as a windows service. Returns the server number on success or false on failure.
- **delete_server(int $serverNumber):bool**: Deletes a server from the internal list and uninstalls the windows service. Returns true on success or false on failure.
- **set_autostart(int $serverNumber, bool $autostart):bool**: Sets the autostart setting for a given server. Returns true on success or false on failure.