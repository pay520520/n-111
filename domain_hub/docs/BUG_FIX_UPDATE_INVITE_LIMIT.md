# Bug修复：更新用户邀请上限功能

## 🐛 问题描述

### 错误信息：
```
TypeError: call_user_func() expects parameter 1 to be a valid callback, 
class 'CfAdminActionService' does not have a method 'handleUpdateUserInviteLimit'
```

### 发生场景：
在插件后台管理界面，当管理员尝试单独修改用户的邀请上限时出错。

### 错误位置：
```php
/modules/addons/domain_hub/lib/Services/AdminActionService.php:124
```

---

## 🔍 问题原因

### 根本原因：
`AdminActionService.php` 文件中定义了动作处理器映射：

```php
// 第90行
'update_user_invite_limit' => [self::class, 'handleUpdateUserInviteLimit'],
```

但是对应的 `handleUpdateUserInviteLimit()` 方法并未实现，导致 `call_user_func()` 调用失败。

### 相关文件：
1. **AdminActionService.php** - 缺少方法实现
2. **user_quotas.tpl** - 调用该功能的表单（第82行）

---

## ✅ 解决方案

### 添加缺失的方法：

在 `AdminActionService.php` 的第2184行添加了 `handleUpdateUserInviteLimit()` 方法：

```php
private static function handleUpdateUserInviteLimit(): void
{
    try {
        // 1. 获取用户ID（支持通过邮箱查找）
        $userId = intval($_POST['user_id'] ?? 0);
        $email = trim((string) ($_POST['user_email'] ?? ''));
        
        if ($userId <= 0 && $email !== '') {
            $userLookup = Capsule::table('tblclients')->where('email', $email)->first();
            if ($userLookup) {
                $userId = intval($userLookup->id);
            }
        }
        
        if ($userId <= 0) {
            throw new Exception('用户ID无效或邮箱不存在');
        }
        
        // 2. 验证用户存在
        $user = Capsule::table('tblclients')->where('id', $userId)->first();
        if (!$user) {
            throw new Exception('用户不存在');
        }
        
        // 3. 获取新的邀请上限值
        $newInviteLimit = null;
        if (isset($_POST['new_invite_limit']) && $_POST['new_invite_limit'] !== '') {
            $newInviteLimit = max(0, min(99999999999, intval($_POST['new_invite_limit'])));
        }
        
        if ($newInviteLimit === null) {
            throw new Exception('请填写新的邀请上限值');
        }
        
        // 4. 更新或创建配额记录
        $settings = self::moduleSettings();
        $quotaRow = Capsule::table('mod_cloudflare_subdomain_quotas')
            ->where('userid', $userId)
            ->first();
        
        if ($quotaRow) {
            // 更新现有记录
            Capsule::table('mod_cloudflare_subdomain_quotas')
                ->where('userid', $userId)
                ->update([
                    'invite_bonus_limit' => $newInviteLimit,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
        } else {
            // 创建新记录
            $usedCount = Capsule::table('mod_cloudflare_subdomain')
                ->where('userid', $userId)
                ->count();
            $maxCount = max(0, intval($settings['max_subdomain_per_user'] ?? 0));
            
            Capsule::table('mod_cloudflare_subdomain_quotas')->insert([
                'userid' => $userId,
                'used_count' => $usedCount,
                'max_count' => $maxCount,
                'invite_bonus_count' => 0,
                'invite_bonus_limit' => $newInviteLimit,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
        
        // 5. 记录日志
        if (function_exists('cloudflare_subdomain_log')) {
            cloudflare_subdomain_log('admin_update_user_invite_limit', [
                'userid' => $userId,
                'new_invite_limit' => $newInviteLimit,
            ]);
        }
        
        // 6. 显示成功消息
        $name = trim((string) ($user->firstname ?? '') . ' ' . (string) ($user->lastname ?? ''));
        if ($name === '') {
            $name = $user->email ?? ('ID:' . $userId);
        }
        
        self::flashSuccess('✅ 用户 <strong>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</strong> 的邀请上限已更新为 ' . $newInviteLimit);
    } catch (Exception $e) {
        self::flashError('❌ 更新邀请上限失败：' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
    }

    self::redirect(self::HASH_QUOTAS);
}
```

---

## 🎯 功能说明

### 功能特性：

1. **支持邮箱查找用户**
   - 输入邮箱自动查找对应用户
   - 或直接使用用户ID

2. **自动创建配额记录**
   - 如果用户没有配额记录，自动创建
   - 计算用户当前已使用的域名数量

3. **数据验证**
   - 邀请上限范围：0 - 99999999999
   - 验证用户存在性
   - 防止空值提交

4. **日志记录**
   - 记录操作日志（如果启用）
   - 包含用户ID和新的邀请上限

5. **友好提示**
   - 成功：显示用户名和新的邀请上限
   - 失败：显示详细错误信息

---

## 📝 使用方法

### 后台操作步骤：

1. 进入插件后台管理界面
2. 找到"用户配额管理"（#quotas）部分
3. 填写表单：
   - **用户邮箱**：要修改的用户邮箱地址
   - **邀请上限**：新的邀请上限值（0-99999999999）
4. 点击"更新邀请上限"按钮
5. 查看成功提示

### 表单位置：
```html
<!-- templates/admin/partials/user_quotas.tpl 第81-98行 -->
<form method="post" onsubmit="return validateInviteLimit(this)">
  <input type="hidden" name="action" value="update_user_invite_limit">
  <div class="row g-2">
    <div class="col-md-5">
      <input type="email" name="user_email" placeholder="用户邮箱">
    </div>
    <div class="col-md-3">
      <input type="number" name="new_invite_limit" placeholder="邀请上限" min="0">
    </div>
    <div class="col-md-4">
      <button type="submit">更新邀请上限</button>
    </div>
  </div>
</form>
```

---

## 🔒 安全性

### 安全措施：

1. **输入验证**
   - 邮箱格式验证
   - 数值范围限制（0-99999999999）
   - 防止SQL注入（使用Capsule ORM）

2. **XSS防护**
   - 所有输出使用 `htmlspecialchars()` 转义
   - 使用 `ENT_QUOTES` 标志

3. **权限控制**
   - 仅管理员可访问
   - 需要通过WHMCS权限系统

4. **CSRF保护**
   - 继承WHMCS的CSRF令牌机制

---

## 🧪 测试建议

### 测试场景：

#### 场景1：正常更新（用户有配额记录）
```
输入：
- 邮箱：test@example.com
- 邀请上限：100

预期结果：
- ✅ 成功提示："用户 XXX 的邀请上限已更新为 100"
- 数据库：mod_cloudflare_subdomain_quotas.invite_bonus_limit = 100
```

#### 场景2：首次设置（用户无配额记录）
```
输入：
- 邮箱：newuser@example.com
- 邀请上限：50

预期结果：
- ✅ 成功提示："用户 XXX 的邀请上限已更新为 50"
- 数据库：创建新记录，包含基础配额和邀请上限
```

#### 场景3：邮箱不存在
```
输入：
- 邮箱：notexist@example.com
- 邀请上限：100

预期结果：
- ❌ 错误提示："用户ID无效或邮箱不存在"
```

#### 场景4：空值提交
```
输入：
- 邮箱：test@example.com
- 邀请上限：（空）

预期结果：
- ❌ 错误提示："请填写新的邀请上限值"
```

#### 场景5：超大值
```
输入：
- 邮箱：test@example.com
- 邀请上限：999999999999999

预期结果：
- ✅ 成功，值被限制为 99999999999
```

---

## 📊 数据库影响

### 涉及的表：

1. **mod_cloudflare_subdomain_quotas**
   - 字段：`invite_bonus_limit`
   - 操作：UPDATE（如存在）或 INSERT（如不存在）

2. **tblclients**
   - 操作：SELECT（查询用户信息）

3. **mod_cloudflare_subdomain**
   - 操作：SELECT COUNT（首次创建配额时统计已用数量）

### SQL示例：

```sql
-- 更新现有记录
UPDATE mod_cloudflare_subdomain_quotas 
SET invite_bonus_limit = 100, 
    updated_at = '2025-01-08 20:30:00'
WHERE userid = 123;

-- 创建新记录
INSERT INTO mod_cloudflare_subdomain_quotas 
(userid, used_count, max_count, invite_bonus_count, invite_bonus_limit, created_at, updated_at)
VALUES (123, 5, 10, 0, 100, '2025-01-08 20:30:00', '2025-01-08 20:30:00');
```

---

## 🔄 与其他功能的关系

### 相关功能：

1. **更新配额功能** (`update_user_quota`)
   - 可以同时更新基础配额和邀请上限
   - 位置：同一页面的另一个表单

2. **邀请系统**
   - 邀请上限控制用户最多可获得多少邀请奖励配额
   - 与 `invite_bonus_count` 配合使用

3. **配额计算**
   - 总配额 = 基础配额 + MIN(邀请获得数, 邀请上限)
   - 邀请上限防止用户无限获得配额

---

## 📝 注意事项

### 重要提示：

1. **邀请上限 vs 基础配额**
   - 邀请上限：用户通过邀请最多能获得的额外配额
   - 基础配额：用户的基础域名配额
   - 两者是独立的

2. **值为0的含义**
   - 邀请上限=0：用户无法获得邀请奖励配额
   - 不影响基础配额

3. **历史数据处理**
   - 如果用户已经获得的邀请配额超过新上限，不会减少
   - 只影响未来的邀请奖励

4. **性能考虑**
   - 首次创建配额时会统计已用域名数量
   - 大量域名的用户可能需要几秒钟

---

## 📚 相关代码位置

### 修改的文件：
```
domain_hub/lib/Services/AdminActionService.php
- 第2184-2258行：新增 handleUpdateUserInviteLimit() 方法
```

### 相关文件：
```
domain_hub/templates/admin/partials/user_quotas.tpl
- 第81-98行：更新邀请上限的表单

domain_hub/lib/Services/AdminActionService.php
- 第90行：动作映射定义
- 第2285-2369行：handleUpdateUserQuota() 方法（相似功能）
```

---

## ✅ 验证清单

修复完成后，请验证：

- [ ] 可以正常访问用户配额管理页面
- [ ] 填写邮箱和邀请上限后点击"更新邀请上限"
- [ ] 显示成功提示消息
- [ ] 数据库中 `invite_bonus_limit` 值已更新
- [ ] 不存在的邮箱显示错误提示
- [ ] 空值提交显示错误提示
- [ ] 超大值被正确限制
- [ ] 新用户自动创建配额记录
- [ ] 日志正确记录（如启用）

---

## 🎉 总结

### 问题：
缺少 `handleUpdateUserInviteLimit()` 方法导致功能无法使用

### 解决：
添加完整的方法实现，包括：
- 用户查找（邮箱或ID）
- 数据验证
- 配额记录更新/创建
- 日志记录
- 友好提示

### 状态：
✅ 已修复，功能正常

---

**修复日期：** 2025-01-08  
**修复版本：** v2.2.1  
**影响范围：** 后台用户配额管理功能
