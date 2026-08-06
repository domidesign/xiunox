<?php
return array (
  // 注册验证码邮件模板
  'user_create_code' => array(
    'subject' => '【{sitename}】注册验证码',
    'body' => '<div style="max-width:600px;margin:0 auto;font-family:sans-serif;">
<h2 style="color:#333;">欢迎注册 {sitename}</h2>
<p>您的注册验证码为：</p>
<div style="font-size:32px;font-weight:bold;color:#0066cc;letter-spacing:5px;padding:10px 0;">{code}</div>
<p>验证码有效期为 10 分钟，请尽快使用。</p>
<p style="color:#999;font-size:12px;">如非本人操作，请忽略此邮件。</p>
</div>',
  ),
  // 密码重置验证码邮件模板
  'user_resetpw_code' => array(
    'subject' => '【{sitename}】密码重置验证码',
    'body' => '<div style="max-width:600px;margin:0 auto;font-family:sans-serif;">
<h2 style="color:#333;">密码重置</h2>
<p>您正在重置 {sitename} 的密码，验证码为：</p>
<div style="font-size:32px;font-weight:bold;color:#0066cc;letter-spacing:5px;padding:10px 0;">{code}</div>
<p>验证码有效期为 10 分钟，请尽快使用。</p>
<p style="color:#999;font-size:12px;">如非本人操作，请忽略此邮件。</p>
</div>',
  ),
  // 邮箱变更验证码邮件模板
  'email_change_code' => array(
    'subject' => '【{sitename}】邮箱变更验证码',
    'body' => '<div style="max-width:600px;margin:0 auto;font-family:sans-serif;">
<h2 style="color:#333;">邮箱变更验证</h2>
<p>您正在变更 {sitename} 的绑定邮箱，验证码为：</p>
<div style="font-size:32px;font-weight:bold;color:#0066cc;letter-spacing:5px;padding:10px 0;">{code}</div>
<p>验证码有效期为 10 分钟，请尽快使用。</p>
<p style="color:#999;font-size:12px;">如非本人操作，请忽略此邮件。</p>
</div>',
  ),
  // 密码修改成功通知邮件模板（发送到当前邮箱）
  'password_change_notify' => array(
    'subject' => '【{sitename}】您的密码已修改',
    'body' => '<div style="max-width:600px;margin:0 auto;font-family:sans-serif;">
<h2 style="color:#333;">密码修改通知</h2>
<p>您好，{username}：</p>
<p>您的 {sitename} 账号密码已于 {time} 被修改成功。</p>
<p style="color:#999;font-size:12px;">如非本人操作，请立即联系管理员处理，或尽快通过"忘记密码"重置账号。</p>
</div>',
  ),
  // 邮箱改绑成功通知邮件模板（发送到旧邮箱）
  'email_change_notify' => array(
    'subject' => '【{sitename}】您的账号邮箱已变更',
    'body' => '<div style="max-width:600px;margin:0 auto;font-family:sans-serif;">
<h2 style="color:#333;">邮箱变更通知</h2>
<p>您好，{username}：</p>
<p>您的 {sitename} 账号绑定邮箱已于 {time} 由 {old_email} 变更为 {new_email}。</p>
<p style="color:#999;font-size:12px;">如非本人操作，请立即联系管理员处理，以防账号被盗用。</p>
</div>',
  ),
);
?>
