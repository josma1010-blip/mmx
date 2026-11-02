<?php
// Formulario de contacto: guarda en BD y notifica por mail() (opcional)
require __DIR__ . '/_lib/orders.php';
method('POST');
csrf_check();
rate_limit('contact', 5, 900);
$in = input();
if (str_in($in, 'website') !== '') ok(); // honeypot anti-spam
$name = str_in($in, 'name', 150); $email = str_in($in, 'email', 190); $phone = str_in($in, 'phone', 30);
$subject = str_in($in, 'subject', 200); $msg = str_in($in, 'message', 5000);
$errs = [];
if (mb_strlen($name) < 2) $errs['name'] = 'Escribe tu nombre';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errs['email'] = 'Correo inválido';
if (mb_strlen($msg) < 10) $errs['message'] = 'El mensaje debe tener al menos 10 caracteres';
if ($errs) fail('Revisa el formulario', 422, ['fields' => $errs]);
record_attempt('contact', $email, true);
db()->prepare('INSERT INTO contact_messages (name,email,phone,subject,message,ip) VALUES (?,?,?,?,?,?)')->execute([$name, $email, $phone ?: null, $subject ?: null, $msg, client_ip()]);
if (setting('notify_email')) send_mail(setting('notify_email'), 'Nuevo mensaje de contacto: ' . ($subject ?: $name), "De: $name <$email> $phone\n\n$msg");
ok(['message' => '¡Gracias! Recibimos tu mensaje y te responderemos pronto.']);
