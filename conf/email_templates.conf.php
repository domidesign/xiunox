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
);
?>
