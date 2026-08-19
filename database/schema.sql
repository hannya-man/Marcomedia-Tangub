-- ============================================================
-- Marcomedia POS - Database Schema (MySQL)
-- Handles variant-based stock (t-shirt sizes, trophy sizes, etc.)
-- ============================================================

CREATE DATABASE IF NOT EXISTS marcomedia_pos;
USE marcomedia_pos;

-- ---------------------------------------
-- USERS (staff/cashiers/admin)
-- ---------------------------------------
CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin','cashier') NOT NULL DEFAULT 'cashier',
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL
);

-- ---------------------------------------
-- CATEGORIES
-- ---------------------------------------
CREATE TABLE categories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(120) NOT NULL UNIQUE,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL
);

-- ---------------------------------------
-- PRODUCTS (the general item, e.g. "Sublimation T-Shirt", "Trophy Model A")
-- has_variants = does it come in sizes/versions
-- track_inventory = does it draw down stock at all (custom one-offs can be false)
-- ---------------------------------------
CREATE TABLE products (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id BIGINT UNSIGNED NULL,
    name VARCHAR(150) NOT NULL,
    sku VARCHAR(60) NOT NULL UNIQUE,
    description TEXT NULL,
    base_price DECIMAL(10,2) NOT NULL DEFAULT 0,
    has_variants TINYINT(1) NOT NULL DEFAULT 0,
    track_inventory TINYINT(1) NOT NULL DEFAULT 1,
    image_path VARCHAR(255) NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
);

-- ---------------------------------------
-- PRODUCT VARIANTS
-- This is the table that solves "sizes have different stock".
-- Every sellable, stockable unit is a ROW here, not on the product.
-- Product "Sublimation T-Shirt" -> variants: S, M, L, XL, XXL
-- Product "Trophy Model A" -> variants: 6in, 8in, 10in, 12in
-- Stock, price, and low-stock threshold all live PER VARIANT.
-- ---------------------------------------
CREATE TABLE product_variants (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id BIGINT UNSIGNED NOT NULL,
    variant_name VARCHAR(100) NOT NULL,      -- "Small (S)", "10 inches", "Red / Large"
    sku VARCHAR(60) NOT NULL UNIQUE,
    attributes JSON NULL,                    -- {"size":"M","color":"Black"}
    price DECIMAL(10,2) NULL,                -- overrides product.base_price if set
    cost_price DECIMAL(10,2) NULL,
    stock_quantity INT NOT NULL DEFAULT 0,
    low_stock_threshold INT NOT NULL DEFAULT 5,
    reorder_point INT NOT NULL DEFAULT 10,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    CONSTRAINT fk_variants_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    INDEX idx_variant_stock (stock_quantity, low_stock_threshold)
);

-- ---------------------------------------
-- INVENTORY MOVEMENTS (audit trail - every stock change logged here)
-- quantity is signed: positive = added, negative = removed
-- ---------------------------------------
CREATE TABLE inventory_movements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_variant_id BIGINT UNSIGNED NOT NULL,
    type ENUM('stock_in','sale','adjustment','return','void') NOT NULL,
    quantity INT NOT NULL,
    stock_before INT NOT NULL,
    stock_after INT NOT NULL,
    reference_type VARCHAR(50) NULL,
    reference_id BIGINT UNSIGNED NULL,
    remarks VARCHAR(255) NULL,
    user_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NULL DEFAULT NULL,
    CONSTRAINT fk_movement_variant FOREIGN KEY (product_variant_id) REFERENCES product_variants(id) ON DELETE CASCADE,
    CONSTRAINT fk_movement_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- ---------------------------------------
-- CUSTOMERS (optional, walk-ins allowed)
-- ---------------------------------------
CREATE TABLE customers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    phone VARCHAR(30) NULL,
    email VARCHAR(150) NULL,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL
);

-- ---------------------------------------
-- SALES (transaction header)
-- ---------------------------------------
CREATE TABLE sales (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_number VARCHAR(30) NOT NULL UNIQUE,
    customer_id BIGINT UNSIGNED NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    subtotal DECIMAL(10,2) NOT NULL DEFAULT 0,
    discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    tax_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    total_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    amount_paid DECIMAL(10,2) NOT NULL DEFAULT 0,
    change_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    payment_method ENUM('cash','gcash','card','bank_transfer') NOT NULL DEFAULT 'cash',
    status ENUM('completed','voided','pending') NOT NULL DEFAULT 'completed',
    notes VARCHAR(255) NULL,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    CONSTRAINT fk_sales_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    CONSTRAINT fk_sales_user FOREIGN KEY (user_id) REFERENCES users(id)
);

-- ---------------------------------------
-- SALE ITEMS (line items per transaction)
-- product_variant_id nullable for pure custom jobs with no stock unit
-- customization_details holds e.g. "Engrave: Juan Dela Cruz, Champion 2026"
-- ---------------------------------------
CREATE TABLE sale_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sale_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    product_variant_id BIGINT UNSIGNED NULL,
    item_name VARCHAR(150) NOT NULL,
    variant_name VARCHAR(100) NULL,
    customization_details TEXT NULL,
    quantity INT NOT NULL DEFAULT 1,
    unit_price DECIMAL(10,2) NOT NULL,
    discount DECIMAL(10,2) NOT NULL DEFAULT 0,
    subtotal DECIMAL(10,2) NOT NULL,
    CONSTRAINT fk_items_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
    CONSTRAINT fk_items_product FOREIGN KEY (product_id) REFERENCES products(id),
    CONSTRAINT fk_items_variant FOREIGN KEY (product_variant_id) REFERENCES product_variants(id) ON DELETE SET NULL
);

-- ---------------------------------------
-- SEED DATA (matches the shop flyer: sublimation shirt + trophy)
-- ---------------------------------------
INSERT INTO categories (name, slug, created_at, updated_at) VALUES
('Apparel / Sublimation', 'apparel-sublimation', NOW(), NOW()),
('Trophies & Plaques', 'trophies-plaques', NOW(), NOW()),
('Customized Items', 'customized-items', NOW(), NOW());

INSERT INTO products (category_id, name, sku, description, base_price, has_variants, track_inventory, status, created_at, updated_at) VALUES
(1, 'Sublimation T-Shirt', 'TSHIRT-SUB-001', 'Full sublimation printed t-shirt, various sizes', 250.00, 1, 1, 'active', NOW(), NOW()),
(2, 'Trophy Model A', 'TROPHY-A-001', 'Customizable engraved trophy, various sizes', 350.00, 1, 1, 'active', NOW(), NOW());

INSERT INTO product_variants (product_id, variant_name, sku, attributes, price, stock_quantity, low_stock_threshold, reorder_point, status, created_at, updated_at) VALUES
(1, 'Small (S)', 'TSHIRT-SUB-001-S', '{"size":"S"}', 250.00, 20, 5, 10, 'active', NOW(), NOW()),
(1, 'Medium (M)', 'TSHIRT-SUB-001-M', '{"size":"M"}', 250.00, 30, 5, 10, 'active', NOW(), NOW()),
(1, 'Large (L)', 'TSHIRT-SUB-001-L', '{"size":"L"}', 260.00, 25, 5, 10, 'active', NOW(), NOW()),
(1, 'X-Large (XL)', 'TSHIRT-SUB-001-XL', '{"size":"XL"}', 280.00, 15, 5, 10, 'active', NOW(), NOW()),
(1, 'XX-Large (XXL)', 'TSHIRT-SUB-001-XXL', '{"size":"XXL"}', 300.00, 4, 5, 10, 'active', NOW(), NOW()),
(2, '6 inches', 'TROPHY-A-001-6IN', '{"size":"6in"}', 300.00, 10, 3, 6, 'active', NOW(), NOW()),
(2, '8 inches', 'TROPHY-A-001-8IN', '{"size":"8in"}', 350.00, 8, 3, 6, 'active', NOW(), NOW()),
(2, '10 inches', 'TROPHY-A-001-10IN', '{"size":"10in"}', 420.00, 2, 3, 6, 'active', NOW(), NOW());

INSERT INTO users (name, email, password, role, created_at, updated_at) VALUES
('Admin User', 'admin@marcomedia.test', '$2y$10$replaceWithARealBcryptHashOnRegister', 'admin', NOW(), NOW());
-- NOTE: register through Laravel's auth so the password gets hashed properly, that value above is a placeholder.
