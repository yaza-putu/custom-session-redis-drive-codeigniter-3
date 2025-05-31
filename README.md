# custom-session-redis-drive-codeigniter-3
The default driver includes a mechanism that waits for a session read lock, which can negatively impact application performance due to the wait time. To improve this, we add a timeout to the session read lock. This prevents the application from waiting too long before continuing to the next process.
