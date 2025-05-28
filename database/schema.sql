
CREATE TABLE reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(50),
    message TEXT,
    image_url TEXT,
    latitude DOUBLE,
    longitude DOUBLE,
    status VARCHAR(50) DEFAULT 'รับเรื่องแล้ว',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
