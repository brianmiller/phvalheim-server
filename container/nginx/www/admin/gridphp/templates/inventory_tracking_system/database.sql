-- MySQL dump 10.13  Distrib 8.2.0, for Win64 (x86_64)
--
-- Host: 127.0.0.1    Database: ct_inventory_tracking_system
-- ------------------------------------------------------
-- Server version	5.6.35-log

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Current Database: `ct_inventory_tracking_system`
--

CREATE DATABASE /*!32312 IF NOT EXISTS*/ `ct_inventory_tracking_system` /*!40100 DEFAULT CHARACTER SET utf8 */;

USE `ct_inventory_tracking_system`;

--
-- Table structure for table `tb_manufacturers`
--

DROP TABLE IF EXISTS `tb_manufacturers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tb_manufacturers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `name` varchar(255) DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `contact_name` varchar(255) DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone_number` varchar(255) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=67 DEFAULT CHARSET=utf8;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_manufacturers`
--

LOCK TABLES `tb_manufacturers` WRITE;
/*!40000 ALTER TABLE `tb_manufacturers` DISABLE KEYS */;
INSERT INTO `tb_manufacturers` VALUES (56,'2024-06-09 22:53:43','2024-07-01 01:39:41','Oliver Jackson','https://picsum.photos/150/100?r=22','Abu Ghufran','San Francisco','oliver@email.com','(123) 456-7890','123 Powder Lane, 12211'),(57,'2024-06-09 22:53:43','2024-06-09 22:54:21','Handcrafted by Ellie','https://picsum.photos/150/100?r=82','Ellie Wood','San Jose','ellie@email.com','(123) 456-7890','1001 Table St, 11111'),(58,'2024-06-09 22:53:43','2024-06-09 22:54:21','Made by Thomas','https://picsum.photos/150/100?r=86','Thomas Train','San Bruno','thomas@email.com','(123) 456-7890','321 Mogul Drive, 32112'),(59,'2024-06-09 22:53:43','2024-06-09 22:54:21','The Silver Yarn','https://picsum.photos/150/100?r=8','Lisa Barns','Millbrae','lisa@email.com','(123) 456-7890','593 Hollywood Street, 86648'),(60,'2024-06-09 22:53:43','2024-06-09 22:54:21','Sweet Treats Studio','https://picsum.photos/150/100?r=50','Rosa Ng','San Francisco','rosa@email.com','(123) 456-7890','123 Centrel Perk, 12353'),(61,'2024-06-09 22:53:43','2024-06-09 22:54:21','Felix Road Studio','https://picsum.photos/150/100?r=92','Felix Villanueva','Palo Alto','felix@email.com','(123) 456-7890','738 Hoffman Heights, 94023'),(62,'2024-06-09 22:53:43','2024-07-01 01:39:53','Satsuma Leather Makers','https://picsum.photos/150/100?r=57','Sasha Stockton','Oakland','sasha@email.com','(123) 456-7890','3522 Pages Street, 74932'),(63,'2024-06-09 22:53:43','2024-06-09 22:54:21','Rachel Design','https://picsum.photos/150/100?r=58','Rachel Chan','Berkeley','rachel@email.com','(123) 456-7890','58 Sciennes Avenue, 43294'),(64,'2024-06-09 22:53:43','2024-06-09 22:54:21','Indigo Turtle Design','https://picsum.photos/150/100?r=72','Raphael Solane','Millbrae','raphael@email.com','(123) 456-7890','173 Dubice Street, 27194'),(65,'2024-06-09 22:53:43','2024-06-09 22:54:21','Leather Works','https://picsum.photos/150/100?r=88','Richie Gere','San Bruno','richie@email.com','(123) 456-7890','6281 Dolo Perk, 34198'),(66,'2024-06-09 22:53:43','2024-06-09 22:54:21','Handmade Is Better','https://picsum.photos/150/100?r=30','Daisy Bishop','Oakland','daisy@email.com','(123) 456-7890','432 Statement Road, 12353');
/*!40000 ALTER TABLE `tb_manufacturers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tb_product_inventory`
--

DROP TABLE IF EXISTS `tb_product_inventory`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tb_product_inventory` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `product_id` varchar(255) DEFAULT NULL,
  `images` text,
  `product_name` varchar(255) DEFAULT NULL,
  `type` varchar(255) DEFAULT NULL,
  `colors` varchar(255) DEFAULT NULL,
  `style` varchar(255) DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `barcode` varchar(255) DEFAULT NULL,
  `manufacturer` varchar(255) DEFAULT NULL,
  `manufacturer_price` varchar(255) DEFAULT NULL,
  `price` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_product_inventory`
--

LOCK TABLES `tb_product_inventory` WRITE;
/*!40000 ALTER TABLE `tb_product_inventory` DISABLE KEYS */;
INSERT INTO `tb_product_inventory` VALUES (1,'2024-05-11 02:20:23','2024-07-05 06:36:00','1000001','https://picsum.photos/150/100?r=1','Purse Paradise Fantasy','Bag','Burgundy,Sundown Ash,Forest Green,Indigo','Women','1','921138','56','65.00','120.00'),(2,'2024-05-11 02:20:23','2024-06-26 02:19:48','1000002','https://picsum.photos/150/100?r=2','Fashionable Finds','Bag','Burgundy,Sundown Ash,Dessert Brown','Men','2','1234','57','40.00','200.00'),(3,'2024-05-11 02:20:23','2024-06-26 02:19:48','1000003','https://picsum.photos/150/100?r=3','Bag Boutique','Bag','Navy Blue,Forest Green,Indigo','Women','5','96619723829','57','45.00','250.00'),(4,'2024-05-11 02:20:23','2024-06-26 02:19:48','1000004','https://picsum.photos/150/100?r=4','Trendy Dress','Scarf','Wool White','Women','5','96376182637','59','15','60'),(5,'2024-05-11 02:20:23','2024-06-26 05:50:13','1000005','https://picsum.photos/150/100?r=5','Accessorize Me','Scarf','Burgundy,Dessert Brown','Women','4','961251739563','59','15.00','60.00'),(6,'2024-05-11 02:20:23','2024-07-01 01:37:22','1000006','https://picsum.photos/150/100?r=6','Headaholic','Head Accessory','Sundown Ash,Dessert Brown','Unisex','1','956382','58','30.00','80.00'),(7,'2024-05-11 02:20:23','2024-06-26 02:19:48','1000007','https://picsum.photos/150/100?r=7','The Bag Emporium','Bag','Dessert Brown,Burgundy,Navy Blue','Men','2','973618395','62','35','100'),(8,'2024-05-11 02:20:23','2024-06-26 02:19:48','1000008','https://picsum.photos/150/100?r=8','Handbag Haven','Bag','Dessert Brown,Navy Blue,Forest Green','Men','3','97626124','62','150','230'),(9,'2024-05-11 02:20:23','2024-06-26 02:19:48','1000009','https://picsum.photos/150/100?r=9','Carry Couture','Scarf','Burgundy','Men','5','9475384','61','15','60'),(10,'2024-05-11 02:20:23','2024-06-26 02:19:48','1000010','https://picsum.photos/150/100?r=10','Stylish Satchels','Head Accessory','Sundown Ash','Men','6','96236432747232','58','30','85'),(11,'2024-05-11 02:20:23','2024-06-26 02:19:48','1000011','https://picsum.photos/150/100?r=11','Fashion Forward Bags','Bag','Burgundy,Dessert Brown,Navy Blue','Children','2','97626534','63','30','85'),(12,'2024-05-11 02:20:23','2024-06-26 07:11:54','1000012','https://picsum.photos/150/100?r=12','Carry Chic','Bag','Sundown Ash,Forest Green,Indigo','Children','4','97734854385443','63','40.00','115.00'),(13,'2024-05-11 02:20:23','2024-06-26 02:19:48','1000013','https://picsum.photos/150/100?r=13','Cool Blankets','Blanket','Burgundy,Forest Green,Dessert Brown','Dogs','8','972364632','64','20','65'),(14,'2024-05-11 02:20:23','2024-06-26 02:19:48','1000014','https://picsum.photos/150/100?r=14','Heads Paradise','Head Accessory','Sundown Ash','Dogs','6','9734754832','60','40','85'),(15,'2024-05-11 02:20:23','2024-06-26 02:19:48','1000015','https://picsum.photos/150/100?r=15','Bagtastic','Box','Sundown Ash,Dessert Brown','Dogs','8','9435743832','64','10','40'),(16,'2024-05-11 02:20:23','2024-06-26 02:19:48','1000016','https://picsum.photos/150/100?r=16','The Bag Closet','Scarf','Sundown Ash','Dogs','4','934574382','59','25','65'),(17,'2024-05-11 02:20:23','2024-06-26 02:19:48','1000017','https://picsum.photos/150/100?r=17','Fashion Forward','Bag','Dessert Brown','Cats','1','934574332','66','10','35'),(18,'2024-05-11 02:20:23','2024-06-26 02:19:48','1000018','https://picsum.photos/150/100?r=18','Tote-Ally Trendy','Bag','Dessert Brown','Cats','1','9734584392','65','15','45'),(19,'2024-06-26 02:55:13','2024-07-10 01:16:10','1000019','temp/gridphp-laravel-step1.png','Potato Sticks','Box','Navy Blue','Men','4','123987123','58','80.00','100.00');
/*!40000 ALTER TABLE `tb_product_inventory` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tb_purchase_orders`
--

DROP TABLE IF EXISTS `tb_purchase_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tb_purchase_orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `order_number` int(11) DEFAULT NULL,
  `order_date` varchar(255) DEFAULT NULL,
  `status` varchar(255) DEFAULT NULL,
  `manufacturer` varchar(255) DEFAULT NULL,
  `product` varchar(255) DEFAULT NULL,
  `quantity` int(11) DEFAULT NULL,
  `units_arrived` varchar(255) DEFAULT NULL,
  `arrive_by` varchar(255) DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `invoice` varchar(255) DEFAULT NULL,
  `paid` varchar(255) DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=79 DEFAULT CHARSET=utf8;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_purchase_orders`
--

LOCK TABLES `tb_purchase_orders` WRITE;
/*!40000 ALTER TABLE `tb_purchase_orders` DISABLE KEYS */;
INSERT INTO `tb_purchase_orders` VALUES (52,'2024-06-28 03:42:51','2024-07-01 02:28:23',1,'2016-12-15','Order Sent','56','1',35,'35','2017-01-17','https://picsum.photos/150/100?r=72','https://picsum.photos/150/100?r=38','1','#1 - 1000001 - Oliver Jackson'),(53,'2024-06-28 03:42:51','2024-06-29 08:50:34',2,'2017-12-26','Arrived','57','3',25,'25','2017-01-21','https://picsum.photos/150/100?r=45','https://picsum.photos/150/100?r=52','checked','#2 - 1000003 - Handcrafted by Ellie'),(54,'2024-06-28 03:42:52','2024-06-29 08:50:34',3,'2017-01-06','Arrived','58','6',20,'20','2017-01-28','https://picsum.photos/150/100?r=32','https://picsum.photos/150/100?r=35','checked','#3 - 1000006 - Made by Thomas'),(55,'2024-06-28 03:42:52','2024-06-29 08:50:34',4,'2017-01-07','Arrived','60','14',10,'10','2017-02-01','https://picsum.photos/150/100?r=22','https://picsum.photos/150/100?r=68','checked','#4 - 1000014 - Sweet Treats Studio'),(56,'2024-06-28 03:42:52','2024-06-29 08:50:34',5,'2017-01-08','Arrived','64','15',5,'5','2017-02-03','https://picsum.photos/150/100?r=71','https://picsum.photos/150/100?r=67','checked','#5 - 1000015 - Indigo Turtle Design'),(57,'2024-06-28 03:42:52','2024-06-29 08:50:34',10,'2017-01-10','Arrived','62','7',15,'15','2017-02-04','https://picsum.photos/150/100?r=29','https://picsum.photos/150/100?r=24','checked','#10 - 1000007 - Satsuma Leather Goods'),(58,'2024-06-28 03:42:52','2024-06-29 08:50:34',6,'2017-01-12','Arrived','61','9',5,'5','2017-02-28','https://picsum.photos/150/100?r=5','https://picsum.photos/150/100?r=2','checked','#6 - 1000009 - Felix Road Studio'),(59,'2024-06-28 03:42:52','2024-06-29 08:50:34',7,'2017-01-13','Arrived','65','18',10,'10','2017-02-06','https://picsum.photos/150/100?r=50','https://picsum.photos/150/100?r=74','checked','#7 - 1000018 - Leather Works'),(60,'2024-06-28 03:42:52','2024-06-29 08:50:34',8,'2017-01-15','Arrived','66','17',18,'18','2017-02-07','https://picsum.photos/150/100?r=23','https://picsum.photos/150/100?r=90','checked','#8 - 1000017 - Handmade Is Better'),(61,'2024-06-28 03:42:52','2024-06-29 08:50:34',9,'2017-01-15','Arrived','62','8',20,'20','2017-02-09','https://picsum.photos/150/100?r=45','https://picsum.photos/150/100?r=98','checked','#9 - 1000008 - Satsuma Leather Goods'),(62,'2024-06-28 03:42:52','2024-06-29 08:50:34',11,'2017-01-17','Arrived','63','12',40,'40','2017-02-12','https://picsum.photos/150/100?r=12','https://picsum.photos/150/100?r=83','','#11 - 1000012 - Rachel Design'),(63,'2024-06-28 03:42:52','2024-06-29 08:50:34',12,'2017-01-20','Arrived','63','11',25,'25','2017-02-13','https://picsum.photos/150/100?r=89','https://picsum.photos/150/100?r=23','checked','#12 - 1000011 - Rachel Design'),(64,'2024-06-28 03:42:52','2024-06-29 08:50:34',13,'2017-01-22','Arrived','59','4',30,'30','2017-02-15','https://picsum.photos/150/100?r=62','https://picsum.photos/150/100?r=24','checked','#13 - 1000004 - The Silver Yarn'),(65,'2024-06-28 03:42:52','2024-06-29 08:50:34',14,'2017-01-24','Arrived','57','2',30,'30','2017-02-24','https://picsum.photos/150/100?r=26','https://picsum.photos/150/100?r=40','','#14 - 1000002 - Handcrafted by Ellie'),(66,'2024-06-28 03:42:52','2024-06-29 08:50:34',15,'2017-01-27','Arrived','59','5',20,'20','2017-02-21','https://picsum.photos/150/100?r=86','https://picsum.photos/150/100?r=65','checked','#15 - 1000005 - The Silver Yarn'),(67,'2024-06-28 03:42:52','2024-06-29 08:50:34',16,'2017-01-28','Packaging','58','10',25,'25','2017-03-11','https://picsum.photos/150/100?r=69','https://picsum.photos/150/100?r=41','checked','#16 - 1000010 - Made by Thomas'),(68,'2024-06-28 03:42:52','2024-06-29 08:50:34',17,'2017-02-03','Arrived','64','13',10,'10','2017-02-18','https://picsum.photos/150/100?r=15','https://picsum.photos/150/100?r=3','','#17 - 1000013 - Indigo Turtle Design'),(69,'2024-06-28 03:42:52','2024-06-29 08:50:34',18,'2017-02-04','Arrived','59','16',15,'15','2017-02-28','https://picsum.photos/150/100?r=55','https://picsum.photos/150/100?r=27','','#18 - 1000016 - The Silver Yarn'),(70,'2024-06-28 03:42:52','2024-06-29 08:56:14',20,'2017-02-06','Shipping','59','4',30,'30','2017-03-19','https://picsum.photos/150/100?r=42','https://picsum.photos/150/100?r=80','1','#20 - 1000004 - The Silver Yarn'),(71,'2024-06-28 03:42:52','2024-06-29 08:50:34',21,'2017-02-09','In Production','57','2',30,'30','2017-03-21','https://picsum.photos/150/100?r=90','https://picsum.photos/150/100?r=15','','#21 - 1000002 - Handcrafted by Ellie'),(72,'2024-06-28 03:42:52','2024-06-29 08:50:34',22,'2017-02-13','In Production','59','5',20,'20','2017-03-24','https://picsum.photos/150/100?r=55','https://picsum.photos/150/100?r=43','checked','#22 - 1000005 - The Silver Yarn'),(73,'2024-06-28 03:42:52','2024-06-29 08:50:34',23,'2017-02-14','Arrived','58','10',25,'25','2017-02-17','https://picsum.photos/150/100?r=52','https://picsum.photos/150/100?r=49','checked','#23 - 1000010 - Made by Thomas'),(74,'2024-06-28 03:42:52','2024-06-29 08:50:34',24,'2017-02-15','Order Sent','64','13',10,'10','2017-04-01','https://picsum.photos/150/100?r=100','https://picsum.photos/150/100?r=64','','#24 - 1000013 - Indigo Turtle Design'),(75,'2024-06-28 03:42:52','2024-06-29 08:50:34',25,'2017-02-16','Order Sent','59','16',15,'15','2017-03-01','https://picsum.photos/150/100?r=9','https://picsum.photos/150/100?r=6','','#25 - 1000016 - The Silver Yarn'),(76,'2024-06-28 03:52:01','2024-07-01 02:56:32',26,'2024-06-27','Arrived','57','1',0,'2','2024-06-19','','','1','#26 - 1000001 - Handcrafted by Ellie'),(77,'2024-06-29 08:47:20','2024-07-01 01:42:33',27,'2024-06-29','Arrived','56','',0,'0','2024-06-28','','','1','#27 - 1000001 - Oliver Jackson'),(78,'2024-07-01 23:44:50','2024-07-01 23:44:50',28,'2024-07-01','Order Sent','61','1',250,'0','2024-07-17','','','1','#28 - 1000001 - Felix Road Studio');
/*!40000 ALTER TABLE `tb_purchase_orders` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tb_sales_orders`
--

DROP TABLE IF EXISTS `tb_sales_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tb_sales_orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `product` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sale_platform` varchar(255) DEFAULT NULL,
  `quantity` int(11) DEFAULT NULL,
  `order_number` int(11) DEFAULT NULL,
  `order_date` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=93 DEFAULT CHARSET=utf8;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_sales_orders`
--

LOCK TABLES `tb_sales_orders` WRITE;
/*!40000 ALTER TABLE `tb_sales_orders` DISABLE KEYS */;
INSERT INTO `tb_sales_orders` VALUES (73,'2024-06-26 07:25:23','2024-06-27 04:48:47','1','Online',4,1,'2017-02-17'),(74,'2024-06-26 07:25:23','2024-06-27 04:48:47','2','In Store',2,2,'2017-02-17'),(75,'2024-06-26 07:25:23','2024-06-27 04:48:47','3','Farmers Market',5,3,'2017-02-18'),(76,'2024-06-26 07:25:23','2024-06-27 04:48:47','4','Farmers Market',3,4,'2017-02-18'),(77,'2024-06-26 07:25:23','2024-06-27 04:48:47','5','Farmers Market',6,5,'2017-02-18'),(78,'2024-06-26 07:25:23','2024-06-27 04:48:47','6','In Store',4,6,'2017-02-19'),(79,'2024-06-26 07:25:23','2024-06-27 04:48:47','7','In Store',4,7,'2017-02-20'),(80,'2024-06-26 07:25:23','2024-06-27 04:48:47','8','In Store',2,8,'2017-02-20'),(81,'2024-06-26 07:25:23','2024-06-27 04:48:47','9','In Store',3,9,'2017-02-21'),(82,'2024-06-26 07:25:23','2024-06-27 04:48:47','10','In Store',4,10,'2017-02-21'),(83,'2024-06-26 07:25:23','2024-06-27 04:48:47','11','Online',2,11,'2017-02-22'),(84,'2024-06-26 07:25:23','2024-06-27 04:48:47','15','In Store',5,12,'2017-02-22'),(85,'2024-06-26 07:25:23','2024-06-27 04:48:47','13','Farmers Market',8,13,'2017-02-23'),(86,'2024-06-26 07:25:23','2024-06-27 04:48:47','12','Farmers Market',10,14,'2017-02-23'),(87,'2024-06-26 07:25:23','2024-06-27 04:48:47','16','In Store',3,15,'2017-02-24'),(88,'2024-06-26 07:25:23','2024-06-27 04:48:47','14','In Store',5,16,'2017-02-25'),(89,'2024-06-26 07:25:23','2024-06-27 04:48:47','18','Online',7,17,'2017-02-26'),(90,'2024-06-26 07:25:23','2024-06-27 04:48:47','17','In Store',2,18,'2017-02-27'),(91,'2024-06-26 08:51:23','2024-06-27 04:55:07','1','In Store',2,0,'2024-06-29'),(92,'2024-06-28 04:10:22','2025-03-13 02:30:49','1','Online',4,19,'2024-06-28');
/*!40000 ALTER TABLE `tb_sales_orders` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tb_settings`
--

DROP TABLE IF EXISTS `tb_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tb_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `title` varchar(255) DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `value` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_settings`
--

LOCK TABLES `tb_settings` WRITE;
/*!40000 ALTER TABLE `tb_settings` DISABLE KEYS */;
INSERT INTO `tb_settings` VALUES (1,'2025-03-16 08:11:48','2025-03-16 08:43:46','Application Name','app_name','Inventory Tracking System'),(2,'2025-03-16 08:11:48','2025-03-16 08:11:48','Enable Authentication','auth_enabled','0');
/*!40000 ALTER TABLE `tb_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tb_users`
--

DROP TABLE IF EXISTS `tb_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tb_users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `name` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `role` varchar(255) DEFAULT NULL,
  `status` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_users`
--

LOCK TABLES `tb_users` WRITE;
/*!40000 ALTER TABLE `tb_users` DISABLE KEYS */;
INSERT INTO `tb_users` VALUES (1,'2025-03-16 08:11:47','2025-03-16 08:11:47','Admin User','admin','$2y$10$r8ZZVSglKuifADO5CRxiGuguuE6pwJcrU.Y72UeRLhGoeeozXDQuG','admin','active');
/*!40000 ALTER TABLE `tb_users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tb_warehouse_locations`
--

DROP TABLE IF EXISTS `tb_warehouse_locations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tb_warehouse_locations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `name` varchar(255) DEFAULT NULL,
  `shorthand` varchar(255) DEFAULT NULL,
  `product_types` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_warehouse_locations`
--

LOCK TABLES `tb_warehouse_locations` WRITE;
/*!40000 ALTER TABLE `tb_warehouse_locations` DISABLE KEYS */;
INSERT INTO `tb_warehouse_locations` VALUES (1,'2024-05-11 02:21:10','2024-07-01 01:39:20','Zone A - Shelf 1','A-1','Bags'),(2,'2024-05-11 02:21:10','2024-05-11 02:21:35','Zone A - Shelf 2','A-2','Bags'),(3,'2024-05-11 02:21:10','2024-05-11 02:21:35','Zone A - Shelf 3','A-3','Bags'),(4,'2024-05-11 02:21:10','2024-05-11 02:21:35','Zone B - Shelf 1','B-1','Scarves'),(5,'2024-05-11 02:21:10','2024-05-11 02:21:35','Zone B - Shelf 2','B-2','Scarves'),(6,'2024-05-11 02:21:10','2024-05-11 02:21:35','Zone C - Shelf 1','C-1','Head Accessories'),(7,'2024-05-11 02:21:10','2024-05-11 02:21:35','Zone C - Shelf 2','C-2','Head Accessories'),(8,'2024-05-11 02:21:10','2024-05-11 02:21:35','Zone D - Shelf 1','D-1','Miscellaneous');
/*!40000 ALTER TABLE `tb_warehouse_locations` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2025-04-07 15:47:30
