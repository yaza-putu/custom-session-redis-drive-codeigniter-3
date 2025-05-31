# custom-session-redis-drive-codeigniter-3
The default driver includes a mechanism that waits for a session read lock, which can negatively impact application performance due to the wait time. To improve this, we add a timeout to the session read lock. This prevents the application from waiting too long before continuing to the next process.

## how to install
- copy this file to application/libraries/Session/drivers/Session_redis_driver.php

- enable from config.php
  ```php
  $config['sess_driver'] = 'redis';
  $config['sess_save_path'] = 'tcp://localhost:6379?auth=yourPassword&timeout=2.0';
  ```


### note
Please make the 'session' folder name capitalized to 'Session' in the 'application/libraries/Session/drivers/' directory.