<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * CodeIgniter Session Redis Driver
 * 
 * Why do we need this?
 * The default driver includes a mechanism that waits for a session read lock,
 * which can negatively impact application performance due to the wait time.
 *
 * To improve this, we add a timeout to the session read lock.
 * This prevents the application from waiting too long before continuing to the next process.
 * @package    CodeIgniter
 * @subpackage Libraries
 * @category   Sessions
 * @author     Your Name
 * @link       
 */
class CI_Session_redis_driver extends CI_Session_driver implements SessionHandlerInterface
{
    /**
     * Redis connection
     *
     * @var Redis
     */
    protected $_redis;

    /**
     * Key prefix
     *
     * @var string
     */
    protected $_key_prefix = 'ci_session:';

    /**
     * Lock key suffix
     *
     * @var string
     */
    protected $_lock_key_suffix = ':lock';

    /**
     * Session lock timeout
     *
     * @var int
     */
    protected $_lock_timeout = 300;

    /**
     * Key TTL
     *
     * @var int
     */
    protected $_key_ttl = 7200;

    /**
     * Class constructor
     *
     * @param array $params Configuration parameters
     * @return void
     */
    public function __construct(&$params)
    {
        parent::__construct($params);

        if (empty($this->_config['save_path']))
        {
            log_message('error', 'Session: No Redis save path configured.');
            return;
        }

        if (preg_match('#(?:tcp://)?([^:?]+)(?::(\d+))?(\?.+)?#', $this->_config['save_path'], $matches))
        {
            isset($matches[3]) OR $matches[3] = '';
            $this->_config['save_path'] = array(
                'host' => $matches[1],
                'port' => empty($matches[2]) ? 6379 : $matches[2],
                'password' => preg_match('#auth=([^\s&]+)#', $matches[3], $match) ? $match[1] : NULL,
                'database' => preg_match('#database=(\d+)#', $matches[3], $match) ? (int) $match[1] : NULL,
                'timeout' => preg_match('#timeout=(\d+\.\d+)#', $matches[3], $match) ? (float) $match[1] : NULL
            );

            preg_match('#prefix=([^\s&]+)#', $matches[3], $match) && $this->_key_prefix = $match[1];
        }
        else
        {
            log_message('error', 'Session: Invalid Redis save path format: '.$this->_config['save_path']);
            return;
        }

        if ($this->_config['match_ip'] === TRUE)
        {
            $this->_key_prefix .= $_SERVER['REMOTE_ADDR'].':';
        }

        $this->_key_ttl = (int) $this->_config['expiration'];
    }

    /**
     * Open session
     *
     * @param string $save_path Session save path
     * @param string $name Session name
     * @return bool
     */
    public function open($save_path, $name)
    {
        if (empty($this->_config['save_path']))
        {
            return $this->_failure;
        }

        if ( ! extension_loaded('redis'))
        {
            log_message('error', 'Session: Redis extension is not installed.');
            return $this->_failure;
        }

        $this->_redis = new Redis();

        // Set shorter connection timeout
        $timeout = isset($this->_config['save_path']['timeout']) ? $this->_config['save_path']['timeout'] : 2.0;

        if ( ! $this->_redis->connect(
            $this->_config['save_path']['host'],
            $this->_config['save_path']['port'],
            $timeout
        ))
        {
            log_message('error', 'Session: Unable to connect to Redis with the configured settings.');
            return $this->_failure;
        }

        // Set read timeout to prevent hanging
        $this->_redis->setOption(Redis::OPT_READ_TIMEOUT, 2);

        if (isset($this->_config['save_path']['password']) && ! $this->_redis->auth($this->_config['save_path']['password']))
        {
            log_message('error', 'Session: Unable to authenticate to Redis instance.');
            return $this->_failure;
        }

        if (isset($this->_config['save_path']['database']) && ! $this->_redis->select($this->_config['save_path']['database']))
        {
            log_message('error', 'Session: Unable to select Redis database with index '.$this->_config['save_path']['database']);
            return $this->_failure;
        }

        $this->_success = TRUE;
        return $this->_success;
    }

    /**
     * Read session data
     *
     * @param string $session_id Session ID
     * @return string Session data
     */
    public function read($session_id)
    {
        if (isset($this->_redis) && $this->_get_lock($session_id))
        {
            // Needed by write() to detect session_regenerate_id() calls
			$this->_session_id = $session_id;

            $session_data = $this->_redis->get($this->_key_prefix.$session_id);

            is_string($session_data)
                ? $this->_key_exists = TRUE
                : $session_data = '';

            $this->_fingerprint = md5($session_data);
            return $session_data;
        }

        return $this->_failure;
    }

    /**
     * Write session data
     *
     * @param string $session_id Session ID
     * @param string $session_data Session data
     * @return bool
     */
    public function write($session_id, $session_data)
    {
        if ( ! isset($this->_redis, $this->_lock_key))
        {
            return $this->_failure;
        }

        if ($session_id !== $this->_session_id)
        {
            if ( ! $this->_release_lock() OR ! $this->_get_lock($session_id))
            {
                // Continue without lock if can't get it
                log_message('debug', 'Session: Writing without lock for session '.$session_id);
            }

            $this->_key_exists = FALSE;
            $this->_session_id = $session_id;
        }

        // Update lock timeout only if we have a lock
        if ($this->_lock === TRUE && isset($this->_lock_key))
        {
            $this->_redis->expire($this->_lock_key, 30); // Shorter lock timeout
        }

        if ($this->_fingerprint !== ($fingerprint = md5($session_data)) OR $this->_key_exists === FALSE)
		{
			if ($this->_redis->set($this->_key_prefix.$session_id, $session_data, $this->_config['expiration']))
			{
				$this->_fingerprint = $fingerprint;
				$this->_key_exists = TRUE;
				return $this->_success;
			}

			return $this->_fail();
		}

		return ($this->_redis->setTimeout($this->_key_prefix.$session_id, $this->_config['expiration']))
			? $this->_success
			: $this->_fail();
    }

    /**
     * Close session
     *
     * @return bool
     */
    public function close()
    {
        if (isset($this->_redis))
        {
            try {
                // Release lock if we have one
                if ($this->_lock && isset($this->_lock_key))
                {
                    $this->_redis->del($this->_lock_key);
                    $this->_lock = FALSE;
                    $this->_lock_key = NULL;
                }
                
                // Check connection before closing
                if ($this->_redis->ping() === '+PONG')
                {
                    $this->_release_lock();
                    if (!$this->_redis->close())
                    {
                        log_message('error', 'Session: Failed to close Redis connection properly');
                        return $this->_failure;
                    }
                }
            }
            catch (RedisException $e)
            {
                log_message('error', 'Session: Got RedisException on close(): '.$e->getMessage());
            }

            $this->_redis = NULL;
            return $this->_success;
        }

        return $this->_success;
    }

    /**
     * Destroy session
     *
     * @param string $session_id Session ID
     * @return bool
     */
    public function destroy($session_id)
    {
        if (!isset($this->_redis))
        {
            log_message('error', 'Session: Redis connection not available for destroy operation');
            return $this->_failure;
        }

        try 
        {
            $session_key = $this->_key_prefix.$session_id;
            $lock_key = $session_key.':lock';
            
            // Delete both session data and lock
            $deleted_session = $this->_redis->del($session_key);
            $deleted_lock = $this->_redis->del($lock_key);
            $this->_clear_redis($session_id);
            log_message('debug', 'Session destroy - Session: '.($deleted_session ? 'deleted' : 'not found').', Lock: '.($deleted_lock ? 'deleted' : 'not found'));
            // Consider success if at least session data was deleted or didn't exist
            if ($deleted_session > 0 || !$this->_redis->exists($session_key))
            {
                // Clear internal state
                $this->_fingerprint = NULL;
                $this->_key_exists = FALSE;
                $this->_lock = FALSE;
                $this->_lock_key = NULL;
                $this->_cookie_destroy();
                return $this->_success;
            }
            
            log_message('error', 'Session: Failed to destroy session '.$session_id);
            return $this->_failure;
        }
        catch (RedisException $e)
        {
            log_message('error', 'Session: Redis exception during destroy: '.$e->getMessage());
            return $this->_failure;
        }
    }

    /**
     * Garbage collection
     *
     * @param int $maxlifetime Maximum lifetime
     * @return bool
     */
    public function gc($maxlifetime)
    {
        // Redis handles expiration automatically
        return $this->_success;
    }

    /**
     * Get lock
     *
     * @param string $session_id Session ID
     * @return bool
     */
    protected function _get_lock($session_id)
    {
        // PHP 7 reuses the SessionHandler object on regeneration,
        // so we need to check here if the lock key is for the
        // correct session ID.
        if ($this->_lock_key === $this->_key_prefix.$session_id.':lock')
        {
            return $this->_redis->expire($this->_lock_key, 300);
        }

        // Optimized locking with shorter timeout and fewer attempts
        $lock_key = $this->_key_prefix.$session_id.':lock';
        $attempt = 0;
        $max_attempts = 5; // Reduced from 30 to 5
        $sleep_time = 0.1; // 100ms instead of 1 second

        do
        {
            // Use SET with NX and EX options for atomic operation
            if ($this->_redis->set($lock_key, time(), ['NX', 'EX' => 30])) // 30 seconds lock timeout
            {
                $this->_lock_key = $lock_key;
                $this->_lock = TRUE;
                return TRUE;
            }

            // Check if lock still exists and wait shorter time
            if ($this->_redis->exists($lock_key))
            {
                usleep($sleep_time * 1000000); // Convert to microseconds
                $sleep_time = min($sleep_time * 1.5, 0.5); // Exponential backoff, max 500ms
            }
            else
            {
                // Lock was released, try again immediately
                continue;
            }
        }
        while (++$attempt < $max_attempts);

        // If we can't get lock, proceed without it (less safe but faster)
        log_message('debug', 'Session: Proceeding without lock for '.$this->_key_prefix.$session_id.' after '.$max_attempts.' attempts.');
        $this->_lock = FALSE;
        return TRUE; // Return TRUE to allow session operations to continue
    }
    

    /**
     * Release lock
     *
     * @return bool
     */
    protected function _release_lock()
    {
        if (isset($this->_redis) && $this->_lock)
        {
            if ( ! $this->_redis->del($this->_lock_key))
            {
                log_message('error', 'Session: Error while trying to free lock for '.$this->_lock_key);
                return FALSE;
            }

            $this->_lock_key = NULL;
            $this->_lock = FALSE;
        }

        return TRUE;
    }

    /**
 * Clear session data in Redis
 */
    protected function _clear_redis($session_id)
    {
        if (!isset($this->_redis)) {
            return FALSE;
        }

        $session_key = $this->_key_prefix.$session_id;
        
        // Delete session data
        $result = $this->_redis->del($session_key);
        
        // Also delete the lock if exists
        $lock_key = $session_key.':lock';
        $this->_redis->del($lock_key);

         log_message('error', 'Session: deleted fo session '.$session_id);
        
        return $result > 0;
    }
}