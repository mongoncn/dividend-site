<?php
require_once 'config.php';
header('Content-Type: application/json; charset=utf-8');

try {
    // 创建用户表
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INT PRIMARY KEY AUTO_INCREMENT,
            username VARCHAR(50) UNIQUE NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");

    // 创建股票表
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS stocks (
            id INT PRIMARY KEY AUTO_INCREMENT,
            symbol VARCHAR(20) NOT NULL,
            name VARCHAR(100),
            market VARCHAR(20),
            current_price DECIMAL(10,2),
            dividend_yield DECIMAL(5,2),
            last_updated TIMESTAMP,
            UNIQUE KEY (symbol, market)
        )
    ");

    // 创建持仓表
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS holdings (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            stock_id INT NOT NULL,
            buy_price DECIMAL(10,2) NOT NULL,
            quantity INT NOT NULL,
            buy_date DATE,
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (stock_id) REFERENCES stocks(id) ON DELETE CASCADE
        )
    ");

    // 创建分红记录表
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS dividends (
            id INT PRIMARY KEY AUTO_INCREMENT,
            holding_id INT NOT NULL,
            dividend_date DATE NOT NULL,
            amount_per_share DECIMAL(10,4) NOT NULL,
            quantity INT NOT NULL,
            total_amount DECIMAL(12,2) NOT NULL,
            notes VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (holding_id) REFERENCES holdings(id) ON DELETE CASCADE
        )
    ");

    echo json_encode(['success' => true, 'message' => '数据库表创建成功']);

} catch (PDOException $e) {
    echo json_encode(['error' => '创建表失败: ' . $e->getMessage()]);
}
?>
