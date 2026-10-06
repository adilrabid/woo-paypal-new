<?php
/** Minimal database fixture for the lock's conditional SQL operations. */
function wp_generate_uuid4() { static $sequence = 0; return 'owner-' . ++$sequence; }
function wp_cache_delete(...$args) {}
$GLOBALS['wpdb'] = new class {
    public $options = 'wp_options';
    public $before_update;
    public function prepare($sql, ...$args) { return array($sql, $args); }
    public function get_var($query) { return $GLOBALS['locks'][$query[1][0]] ?? null; }
    public function query($query) {
        [$sql, $args] = $query;
        if (str_starts_with($sql, 'INSERT')) {
            [$key, $value] = $args;
            if (isset($GLOBALS['locks'][$key])) { return 0; }
            $GLOBALS['locks'][$key] = $value;
            return 1;
        }
        if (str_starts_with($sql, 'UPDATE')) {
            [$value, $key, $previous] = $args;
            if ($this->before_update) {
                $callback = $this->before_update;
                $this->before_update = null;
                $callback();
            }
            if ((string) ($GLOBALS['locks'][$key] ?? '') !== (string) $previous) { return 0; }
            $GLOBALS['locks'][$key] = $value;
            return 1;
        }
        if (str_starts_with($sql, 'DELETE')) {
            [$key, $token] = $args;
            if (($GLOBALS['locks'][$key] ?? null) !== $token) { return 0; }
            unset($GLOBALS['locks'][$key]);
            return 1;
        }
        throw new RuntimeException('Unexpected lock SQL');
    }
};
