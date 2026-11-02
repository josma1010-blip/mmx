-- Tienda eSIM & Chips - Esquema MySQL/MariaDB (utf8mb4, InnoDB)
-- Compatible MySQL 5.7+/8.x y MariaDB 10.3+
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;
DROP TABLE IF EXISTS audit_log, contact_messages, payments, esim_codes, order_items, orders, customers, product_images, products, categories, companies, settings, login_attempts, admins;
SET FOREIGN_KEY_CHECKS=1;

CREATE TABLE admins (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(60) NOT NULL UNIQUE,
  email VARCHAR(190) NULL,
  password_hash VARCHAR(255) NOT NULL,
  must_change_password TINYINT(1) NOT NULL DEFAULT 0,
  failed_attempts INT NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  last_login DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  action VARCHAR(30) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  identifier VARCHAR(190) NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_action_ip (action, ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE settings (
  skey VARCHAR(80) PRIMARY KEY,
  svalue TEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  slug VARCHAR(140) NOT NULL UNIQUE,
  sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE companies (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE products (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_id INT UNSIGNED NOT NULL,
  company_id INT UNSIGNED NOT NULL,
  name VARCHAR(180) NOT NULL,
  slug VARCHAR(200) NOT NULL UNIQUE,
  short_description VARCHAR(300) NULL,
  description TEXT NULL,
  price DECIMAL(10,2) NOT NULL,
  compare_price DECIMAL(10,2) NULL,
  type ENUM('esim','fisico') NOT NULL,
  stock INT NOT NULL DEFAULT 0 COMMENT 'Solo para fisicos; eSIM usa esim_codes disponibles',
  available TINYINT(1) NOT NULL DEFAULT 1,
  active TINYINT(1) NOT NULL DEFAULT 1,
  featured TINYINT(1) NOT NULL DEFAULT 0,
  is_example TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_cat (category_id), KEY idx_comp (company_id), KEY idx_active (active, available, type),
  CONSTRAINT fk_prod_cat FOREIGN KEY (category_id) REFERENCES categories(id) ON UPDATE CASCADE,
  CONSTRAINT fk_prod_comp FOREIGN KEY (company_id) REFERENCES companies(id) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_images (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  path VARCHAR(255) NOT NULL,
  alt VARCHAR(200) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  KEY idx_prod (product_id, sort_order),
  CONSTRAINT fk_img_prod FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE customers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  phone VARCHAR(30) NULL,
  street VARCHAR(200) NULL, ext_number VARCHAR(30) NULL, int_number VARCHAR(30) NULL,
  neighborhood VARCHAR(120) NULL, city VARCHAR(120) NULL, state VARCHAR(80) NULL, zip VARCHAR(10) NULL,
  references_text VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE orders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_number VARCHAR(30) NOT NULL UNIQUE,
  access_token_hash CHAR(64) NOT NULL,
  access_token_enc VARCHAR(255) NOT NULL,
  customer_id INT UNSIGNED NOT NULL,
  customer_name VARCHAR(150) NOT NULL,
  customer_email VARCHAR(190) NOT NULL,
  customer_phone VARCHAR(30) NOT NULL,
  shipping_address TEXT NULL,
  has_physical TINYINT(1) NOT NULL DEFAULT 0,
  has_esim TINYINT(1) NOT NULL DEFAULT 0,
  subtotal DECIMAL(10,2) NOT NULL,
  shipping DECIMAL(10,2) NOT NULL DEFAULT 0,
  total DECIMAL(10,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'MXN',
  payment_method ENUM('paypal','manual','simulado') NOT NULL,
  status ENUM('pendiente_pago','pagado','en_proceso','enviado','entregado','cancelado','reembolsado') NOT NULL DEFAULT 'pendiente_pago',
  esim_delivered TINYINT(1) NOT NULL DEFAULT 0,
  paypal_order_id VARCHAR(64) NULL,
  tracking_number VARCHAR(100) NULL,
  notes TEXT NULL,
  admin_notes TEXT NULL,
  ip VARCHAR(45) NULL,
  paid_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_status (status, created_at), KEY idx_cust (customer_id), KEY idx_pp (paypal_order_id),
  CONSTRAINT fk_ord_cust FOREIGN KEY (customer_id) REFERENCES customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE order_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NULL,
  product_name VARCHAR(180) NOT NULL,
  type ENUM('esim','fisico') NOT NULL,
  unit_price DECIMAL(10,2) NOT NULL,
  quantity INT NOT NULL,
  line_total DECIMAL(10,2) NOT NULL,
  KEY idx_order (order_id), KEY idx_prod (product_id),
  CONSTRAINT fk_oi_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_oi_prod FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE esim_codes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  iccid VARCHAR(40) NOT NULL,
  activation_code VARCHAR(500) NOT NULL COMMENT 'LPA:1$SM-DP+$MatchingID',
  smdp_address VARCHAR(190) NULL,
  confirmation_code VARCHAR(60) NULL,
  phone_number VARCHAR(20) NULL,
  qr_image VARCHAR(255) NULL COMMENT 'archivo en uploads/private/qr (no publico)',
  status ENUM('disponible','reservado','entregado','anulado') NOT NULL DEFAULT 'disponible',
  order_id INT UNSIGNED NULL,
  order_item_id INT UNSIGNED NULL,
  notes VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reserved_at DATETIME NULL,
  delivered_at DATETIME NULL,
  UNIQUE KEY uq_iccid (iccid),
  UNIQUE KEY uq_activation (activation_code(190)),
  KEY idx_prod_status (product_id, status), KEY idx_order (order_id),
  CONSTRAINT fk_esim_prod FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
  CONSTRAINT fk_esim_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
  CONSTRAINT fk_esim_item FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  method ENUM('paypal','manual','simulado') NOT NULL,
  provider_ref VARCHAR(100) NULL,
  amount DECIMAL(10,2) NOT NULL,
  currency CHAR(3) NOT NULL,
  status VARCHAR(30) NOT NULL,
  raw_response MEDIUMTEXT NULL,
  created_by VARCHAR(60) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_order (order_id), UNIQUE KEY uq_provider_ref (method, provider_ref),
  CONSTRAINT fk_pay_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE contact_messages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  email VARCHAR(190) NOT NULL,
  phone VARCHAR(30) NULL,
  subject VARCHAR(200) NULL,
  message TEXT NOT NULL,
  ip VARCHAR(45) NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_read (is_read, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor VARCHAR(60) NOT NULL,
  action VARCHAR(60) NOT NULL,
  entity VARCHAR(40) NULL,
  entity_id VARCHAR(40) NULL,
  details TEXT NULL,
  ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_entity (entity, entity_id), KEY idx_action (action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================== DATOS SEMILLA =====================
INSERT INTO settings (skey, svalue) VALUES
('store_name','Conecta MX eSIM'),
('store_tagline','eSIM y chips para México, activación rápida'),
('contact_email','ventas@tudominio.com'),
('contact_phone','+52 55 0000 0000'),
('contact_whatsapp','5215500000000'),
('contact_address','Ciudad de México, México'),
('mail_from','no-reply@tudominio.com'),
('mail_from_name','Conecta MX eSIM'),
('banner_title','Tu eSIM lista en minutos'),
('banner_subtitle','eSIM y chips Telcel, Bait y más. Pago seguro con PayPal o transferencia. Entrega digital inmediata.'),
('banner_cta','Ver catálogo'),
('banner_badge','Promoción: envío gratis en chips desde $499'),
('shipping_cost','99.00'),
('free_shipping_from','499.00'),
('currency','MXN'),
('esim_delivery_mode','auto'),
('reservation_hours','48'),
('low_stock_threshold','5'),
('manual_payment_instructions','Realiza tu transferencia SPEI a:\nBanco: (configurar)\nCLABE: (configurar)\nBeneficiario: (configurar)\nConcepto: tu número de pedido.\nEnvía tu comprobante a ventas@tudominio.com'),
('footer_disclaimer','Distribuidor independiente. No afiliado, patrocinado ni respaldado por Telcel, América Movil, Bait ni Walmart. Las marcas mencionadas pertenecen a sus respectivos titulares.'),
('about_text','Vendemos eSIM y chips de las principales compañías de México con entrega digital y envío a todo el país.'),
('notify_email','ventas@tudominio.com'),
('paypal_enabled','0'),
('manual_enabled','1'),
('paypal_mode','sandbox'),
('paypal_client_id',''),
('paypal_secret',''),
('paypal_webhook_id','');

INSERT INTO categories (id,name,slug,sort_order) VALUES
(1,'eSIM Telcel Prepago','esim-telcel-prepago',1),
(2,'eSIM Telcel registrada y vinculada','esim-telcel-registrada',2),
(3,'Chips físicos Telcel','chips-telcel',3),
(4,'Chips Bait','chips-bait',4),
(5,'eSIM de otras compañías','esim-otras',5);

INSERT INTO companies (id,name) VALUES (1,'Telcel'),(2,'Bait'),(3,'AT&T'),(4,'Movistar'),(5,'Altán / Otros');

INSERT INTO products (id,category_id,company_id,name,slug,short_description,description,price,compare_price,type,stock,available,active,featured,is_example) VALUES
(1,1,1,'[EJEMPLO] eSIM Telcel Prepago Amigo','esim-telcel-prepago-amigo','eSIM prepago lista para activar en tu celular compatible.','Producto de EJEMPLO: edita precio y descripción desde el panel.\n\neSIM Telcel prepago. Recibes un código QR y datos de activación (LPA) por correo y en tu página de pedido tras confirmar el pago. Verifica que tu equipo sea compatible con eSIM y esté liberado.',149.00,199.00,'esim',0,1,1,1,1),
(2,1,1,'[EJEMPLO] eSIM Telcel Prepago + Recarga $100','esim-telcel-prepago-recarga-100','eSIM prepago con recarga inicial incluida.','Producto de EJEMPLO. eSIM Telcel prepago con saldo inicial de $100 (según promoción vigente del operador).',249.00,NULL,'esim',0,1,1,1,1),
(3,2,1,'[EJEMPLO] eSIM Telcel registrada y vinculada','esim-telcel-registrada-vinculada','eSIM con línea registrada y vinculada a tus datos.','Producto de EJEMPLO. Te entregamos la eSIM con el registro/vinculación de línea conforme a la normativa vigente. Se solicitarán tus datos tras la compra.',299.00,NULL,'esim',0,1,1,1,1),
(4,3,1,'[EJEMPLO] Chip Telcel Prepago 5G','chip-telcel-prepago-5g','Chip físico triple corte (nano/micro/estándar).','Producto de EJEMPLO. Chip físico Telcel prepago compatible con 4G/5G. Envío a todo México.',99.00,129.00,'fisico',50,1,1,1,1),
(5,3,1,'[EJEMPLO] Paquete 3 Chips Telcel','paquete-3-chips-telcel','Tres chips Telcel prepago, ideal para negocio o familia.','Producto de EJEMPLO. Paquete de 3 chips físicos Telcel prepago triple corte.',249.00,NULL,'fisico',20,1,1,0,1),
(6,4,2,'[EJEMPLO] Chip Bait Prepago','chip-bait-prepago','Chip Bait con cobertura nacional.','Producto de EJEMPLO. Chip físico Bait triple corte. Recarga en tiendas o en línea.',79.00,NULL,'fisico',40,1,1,1,1),
(7,4,2,'[EJEMPLO] Chip Bait + Recarga $200','chip-bait-recarga-200','Chip Bait con recarga inicial.','Producto de EJEMPLO. Chip Bait con recarga inicial de $200 (según condiciones del operador).',259.00,NULL,'fisico',3,1,1,0,1),
(8,5,3,'[EJEMPLO] eSIM AT&T Prepago','esim-att-prepago','eSIM AT&T México para equipos compatibles.','Producto de EJEMPLO. eSIM AT&T prepago con entrega digital.',169.00,NULL,'esim',0,1,1,0,1),
(9,5,4,'[EJEMPLO] eSIM Movistar Prepago','esim-movistar-prepago','eSIM Movistar con activación sencilla.','Producto de EJEMPLO. eSIM Movistar prepago con entrega digital.',139.00,NULL,'esim',0,1,1,0,1),
(10,5,5,'[EJEMPLO] eSIM Datos Viajero México 10 GB','esim-viajero-mexico-10gb','eSIM de datos para turistas y viajeros.','Producto de EJEMPLO. eSIM solo datos, 10 GB, vigencia 30 días (OMV sobre red Altán).',399.00,449.00,'esim',0,1,1,1,1);

INSERT INTO product_images (product_id,path,alt,sort_order) VALUES
(1,'assets/img/esim-telcel.svg','eSIM Telcel Prepago',0),(1,'assets/img/esim-qr.svg','Código QR de activación',1),
(2,'assets/img/esim-telcel-recarga.svg','eSIM Telcel con recarga',0),
(3,'assets/img/esim-telcel-registrada.svg','eSIM registrada',0),(3,'assets/img/esim-qr.svg','Código QR de activación',1),
(4,'assets/img/chip-telcel.svg','Chip Telcel',0),
(5,'assets/img/chip-telcel-pack.svg','Paquete de chips Telcel',0),
(6,'assets/img/chip-bait.svg','Chip Bait',0),
(7,'assets/img/chip-bait-recarga.svg','Chip Bait con recarga',0),
(8,'assets/img/esim-att.svg','eSIM AT&T',0),
(9,'assets/img/esim-movistar.svg','eSIM Movistar',0),
(10,'assets/img/esim-viajero.svg','eSIM viajero',0);

-- Códigos eSIM de EJEMPLO (FALSOS, solo para pruebas). Anúlalos o elimínalos antes de vender.
INSERT INTO esim_codes (product_id,iccid,activation_code,smdp_address,notes) VALUES
(1,'8952000000000000011','LPA:1$smdp.ejemplo.invalid$EJEMPLO-TELCEL-0001','smdp.ejemplo.invalid','EJEMPLO - no valido'),
(1,'8952000000000000012','LPA:1$smdp.ejemplo.invalid$EJEMPLO-TELCEL-0002','smdp.ejemplo.invalid','EJEMPLO - no valido'),
(1,'8952000000000000013','LPA:1$smdp.ejemplo.invalid$EJEMPLO-TELCEL-0003','smdp.ejemplo.invalid','EJEMPLO - no valido'),
(2,'8952000000000000021','LPA:1$smdp.ejemplo.invalid$EJEMPLO-TELCEL-R-0001','smdp.ejemplo.invalid','EJEMPLO - no valido'),
(2,'8952000000000000022','LPA:1$smdp.ejemplo.invalid$EJEMPLO-TELCEL-R-0002','smdp.ejemplo.invalid','EJEMPLO - no valido'),
(3,'8952000000000000031','LPA:1$smdp.ejemplo.invalid$EJEMPLO-TELCEL-V-0001','smdp.ejemplo.invalid','EJEMPLO - no valido'),
(8,'8952000000000000081','LPA:1$smdp.ejemplo.invalid$EJEMPLO-ATT-0001','smdp.ejemplo.invalid','EJEMPLO - no valido'),
(8,'8952000000000000082','LPA:1$smdp.ejemplo.invalid$EJEMPLO-ATT-0002','smdp.ejemplo.invalid','EJEMPLO - no valido'),
(9,'8952000000000000091','LPA:1$smdp.ejemplo.invalid$EJEMPLO-MOV-0001','smdp.ejemplo.invalid','EJEMPLO - no valido'),
(10,'8952000000000000101','LPA:1$smdp.ejemplo.invalid$EJEMPLO-VIAJERO-0001','smdp.ejemplo.invalid','EJEMPLO - no valido'),
(10,'8952000000000000102','LPA:1$smdp.ejemplo.invalid$EJEMPLO-VIAJERO-0002','smdp.ejemplo.invalid','EJEMPLO - no valido');

-- NOTA: No se crea ningún administrador por defecto. Usa install.php (ver README).
