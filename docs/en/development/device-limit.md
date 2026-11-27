# Online Device Limit Design

## Overview

This document describes the design and implementation of the online device limit feature in Xboard.

## Design Goals

1. Accurate Control
   - Precise counting of online devices
   - Real-time monitoring of device status
   - Accurate device identification

2. Performance Optimization
   - Minimal impact on system performance
   - Efficient device tracking
   - Optimized resource usage

3. User Experience
   - Smooth connection experience
   - Clear error messages
   - Graceful handling of limit exceeded cases

## Implementation Details

### 1. Device Identification

#### Device ID Generation
```php
public function generateDeviceId($user, $request) {
    return md5(
        $user->id . 
        $request->header('User-Agent') . 
        $request->ip()
    );
}
```

#### Device Information Storage
```php
[
    'device_id' => 'unique_device_hash',
    'user_id' => 123,
    'ip' => '192.168.1.1',
    'user_agent' => 'Mozilla/5.0...',
    'last_active' => '2024-03-21 10:00:00'
]
```

### 2. Connection Management

#### Connection Check
```php
public function checkDeviceLimit($user, $deviceId) {
    $onlineDevices = $this->getOnlineDevices($user->id);
    
    if (count($onlineDevices) >= $user->device_limit) {
        if (!in_array($deviceId, $onlineDevices)) {
            throw new DeviceLimitExceededException();
        }
    }
    
    return true;
}
```

#### Device Status Update
```php
public function updateDeviceStatus($userId, $deviceId) {
    Redis::hset(
        "user:{$userId}:devices",
        $deviceId,
        json_encode([
            'last_active' => now(),
            'status' => 'online'
        ])
    );
}
```

### 3. Cleanup Mechanism

#### Inactive Device Cleanup
```php
public function cleanupInactiveDevices() {
    $inactiveThreshold = now()->subMinutes(30);
    
    foreach ($this->getUsers() as $user) {
        $devices = $this->getOnlineDevices($user->id);
        
        foreach ($devices as $deviceId => $info) {
            if ($info['last_active'] < $inactiveThreshold) {
                $this->removeDevice($user->id, $deviceId);
            }
        }
    }
}
```

## Error Handling

### Error Types
1. Device Limit Exceeded
   ```php
   class DeviceLimitExceededException extends Exception {
       protected $message = 'Device limit exceeded';
       protected $code = 4001;
   }
   ```

2. Invalid Device
   ```php
   class InvalidDeviceException extends Exception {
       protected $message = 'Invalid device';
       protected $code = 4002;
   }
   ```

### Error Messages
```php
return [
    'device_limit_exceeded' => 'Maximum number of devices reached',
    'invalid_device' => 'Device not recognized',
    'device_expired' => 'Device session expired'
];
```

## Performance Considerations

1. Cache Strategy
   - Use Redis for device tracking
   - Implement cache expiration
   - Optimize cache structure

2. Database Operations
   - Minimize database queries
   - Use batch operations
   - Implement query optimization

3. Memory Management
   - Efficient data structure
   - Regular cleanup of expired data
   - Memory usage monitoring

## Security Measures

1. Device Verification
   - Validate device information
   - Check for suspicious patterns
   - Implement rate limiting

2. Data Protection
   - Encrypt sensitive information
   - Implement access control
   - Regular security audits

## Future Improvements

1. Enhanced Features
   - Device management interface
   - Device activity history
   - Custom device names

2. Performance Optimization
   - Improved caching strategy
   - Better cleanup mechanism
   - Reduced memory usage

3. Security Enhancements
   - Advanced device fingerprinting
   - Fraud detection
   - Improved encryption

## Device ban log

The panel attempts to write one `warning` entry, `[DeviceBan] Device ban started`,
for each new ban. An active ban produces no additional start entry. The panel
continues processing bans and sending them to connected nodes if its Laravel log
channel fails, including when WebSocket debug logging is enabled.

A ban from the panel's combined count lasts three minutes. For a node-reported
ban, the panel uses the earlier of the reported deadline and three minutes from
its own current time.

| Field | Meaning |
| --- | --- |
| `user_id` | Banned user |
| `source` | `panel`: the panel detected a combined count above `device_limit` while processing an online-device report. `node`: a node reported that it rejected an incoming connection after its device check exceeded the limit. |
| `node_id` | Node whose report started the ban |
| `until`, `until_ms` | End of the ban, as application-timezone time and Unix milliseconds |
| `device_limit`, `device_count`, `ips` | `panel` only: the limit, the counted devices (the larger of distinct IPv4 and distinct IPv6 addresses) and the IPs counted: those reported within the last 300 seconds and not before an earlier ban ended |

A node's device check can combine its local connection IPs, a recent panel
snapshot and the incoming IP. Several exit IPs, such as those from a `dialer-proxy`
group, can therefore trigger either source if the count exceeds the limit.

## Conclusion

This design provides a robust and efficient solution for managing online device limits while maintaining good performance and user experience. Regular monitoring and updates will ensure the system remains effective and secure. 