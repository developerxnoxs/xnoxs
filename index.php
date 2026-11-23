<?php
header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/config.php';

$botUsername = BOT_USERNAME;
$webhookUrl  = "https://{$_SERVER['HTTP_HOST']}/webhook.php";
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($botUsername) ?> — Status</title>
<style>
  body { font-family: sans-serif; background: #0f0f0f; color: #e0e0e0; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
  .card { background: #1a1a2e; border: 1px solid #333; border-radius: 12px; padding: 40px 48px; text-align: center; max-width: 420px; width: 90%; }
  .dot { display: inline-block; width: 12px; height: 12px; background: #4caf50; border-radius: 50%; margin-right: 8px; box-shadow: 0 0 8px #4caf50; }
  h1 { font-size: 1.8em; margin: 0 0 8px; color: #7ecfff; }
  p { margin: 6px 0; color: #aaa; font-size: 0.95em; }
  .tag { background: #16213e; border: 1px solid #444; border-radius: 6px; padding: 6px 14px; font-family: monospace; font-size: 0.85em; color: #7ecfff; word-break: break-all; display: inline-block; margin-top: 10px; }
  .btn { display: inline-block; margin-top: 20px; padding: 10px 24px; background: #0088cc; color: #fff; border-radius: 8px; text-decoration: none; font-weight: bold; font-size: 0.9em; }
  .btn:hover { background: #006aaa; }
</style>
</head>
<body>
<div class="card">
  <h1>🤖 <?= htmlspecialchars($botUsername) ?></h1>
  <p><span class="dot"></span><strong>Active and running</strong></p>
  <p style="margin-top:16px;">Webhook URL:</p>
  <div class="tag"><?= htmlspecialchars($webhookUrl) ?></div>
  <p style="margin-top:18px; font-size:0.82em; color:#666;">Telegram Group Management Bot v3.1<br>PHP <?= phpversion() ?></p>
  <a class="btn" href="https://t.me/<?= htmlspecialchars($botUsername) ?>" target="_blank">💬 Open in Telegram</a>
</div>
</body>
</html>
