-- MySQL dump 10.13  Distrib 8.2.0, for Win64 (x86_64)
--
-- Host: 127.0.0.1    Database: ct_expense_tracker
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
-- Current Database: `ct_expense_tracker`
--

CREATE DATABASE /*!32312 IF NOT EXISTS*/ `ct_expense_tracker` /*!40100 DEFAULT CHARACTER SET utf8 */;

USE `ct_expense_tracker`;

--
-- Table structure for table `tb_accounts`
--

DROP TABLE IF EXISTS `tb_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tb_accounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `name` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_accounts`
--

LOCK TABLES `tb_accounts` WRITE;
/*!40000 ALTER TABLE `tb_accounts` DISABLE KEYS */;
INSERT INTO `tb_accounts` VALUES (2,'2024-07-07 22:49:00','2024-07-07 22:49:00','Bank'),(3,'2024-07-07 22:49:00','2024-07-07 22:49:00','Saving'),(4,'2024-12-19 14:56:11','2024-12-19 14:56:11','Current');
/*!40000 ALTER TABLE `tb_accounts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tb_categories`
--

DROP TABLE IF EXISTS `tb_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tb_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `name` varchar(255) DEFAULT NULL,
  `type` varchar(255) DEFAULT NULL,
  `monthly_budget` int(11) DEFAULT NULL,
  `roworder` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=33 DEFAULT CHARSET=utf8;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_categories`
--

LOCK TABLES `tb_categories` WRITE;
/*!40000 ALTER TABLE `tb_categories` DISABLE KEYS */;
INSERT INTO `tb_categories` VALUES (1,'2024-07-07 23:21:07','2024-07-12 11:04:18','Beauty','Expense',0,9),(2,'2024-07-07 23:21:07','2024-07-12 11:04:18','Electronics','Expense',0,4),(4,'2024-07-07 23:21:07','2024-07-12 11:04:18','Food - Groceries','Expense',0,3),(5,'2024-07-07 23:21:07','2024-07-12 11:04:18','Food - Eating Out','Expense',0,5),(6,'2024-07-07 23:21:07','2024-07-12 11:04:18','Food - Vegetables','Expense',0,6),(7,'2024-07-07 23:21:07','2024-07-12 11:04:18','Food - Fruits','Expense',0,7),(9,'2024-07-07 23:21:07','2024-07-12 11:04:18','Bills - Utilities','Expense',0,10),(10,'2024-07-07 23:21:07','2024-07-12 11:04:18','Bills - Repairs','Expense',0,11),(11,'2024-07-07 23:21:07','2024-07-12 11:04:18','Bills - Rent','Expense',0,12),(12,'2024-07-07 23:21:07','2024-07-12 11:04:18','Bills - Services','Expense',0,13),(14,'2024-07-07 23:21:07','2024-07-12 11:04:18','Vehicle - Fuel','Expense',0,14),(15,'2024-07-07 23:21:07','2024-07-12 11:04:18','Vehicle - Maintenance','Expense',0,15),(16,'2024-07-07 23:21:07','2024-07-12 11:04:18','Clothes','Expense',0,16),(17,'2024-07-07 23:21:07','2024-07-12 11:04:18','Entertainment','Expense',0,18),(18,'2024-07-07 23:21:07','2024-07-12 11:04:18','Charity & Gifts','Expense',0,17),(19,'2024-07-07 23:21:07','2024-07-12 11:04:18','Health & Doctor','Expense',0,19),(20,'2024-07-07 23:21:07','2024-07-12 11:04:18','Education','Expense',0,20),(21,'2024-07-07 23:21:07','2024-07-09 22:02:31','General','Expense',0,22),(22,'2024-07-07 23:21:07','2024-07-09 22:02:31','Kids','Expense',0,23),(23,'2024-07-07 23:21:07','2024-07-09 22:02:31','Shopping','Expense',0,24),(24,'2024-07-07 23:21:07','2024-07-09 22:02:31','Travel','Expense',0,25),(25,'2024-07-07 23:21:07','2024-07-09 22:02:31','Sports','Expense',0,26),(26,'2024-07-07 23:21:07','2024-07-10 23:30:28','Salary Income','Income',0,27),(27,'2024-07-07 23:21:07','2024-07-09 22:02:31','Rental Income','Income',0,28),(28,'2024-07-07 23:21:07','2024-07-10 23:30:19','Gifts Income','Income',0,29),(29,'2024-07-07 23:21:07','2024-07-09 22:02:31','Dues Income','Income',0,30),(30,'2024-07-09 21:54:19','2024-07-12 11:04:18','Food - Dairy','Expense',0,8),(31,'2024-07-09 23:51:21','2024-10-10 16:35:35','Transfer Out','Expense',0,2),(32,'2024-07-09 23:57:17','2024-10-10 16:35:35','Transfer In','Income',0,1);
/*!40000 ALTER TABLE `tb_categories` ENABLE KEYS */;
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
INSERT INTO `tb_settings` VALUES (1,'2025-03-16 08:11:48','2025-03-16 08:11:48','Application Name','app_name','Expense Manager'),(2,'2025-03-16 08:11:48','2025-03-16 08:11:48','Enable Authentication','auth_enabled','0');
/*!40000 ALTER TABLE `tb_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tb_transactions`
--

DROP TABLE IF EXISTS `tb_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tb_transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `amount` int(11) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `category` varchar(255) DEFAULT NULL,
  `account` varchar(255) DEFAULT NULL,
  `date` varchar(255) DEFAULT NULL,
  `attachments` text,
  `details` text NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=55 DEFAULT CHARSET=utf8;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_transactions`
--

LOCK TABLES `tb_transactions` WRITE;
/*!40000 ALTER TABLE `tb_transactions` DISABLE KEYS */;
INSERT INTO `tb_transactions` VALUES (6,'2024-07-09 21:21:22','2024-07-11 17:38:19',27600,'Initial Amount','26','1','2024-07-07 20:19:00',NULL,''),(7,'2024-07-09 21:21:22','2024-07-11 17:38:12',3500,'Cleaning Service','12','1','2024-07-07 20:29:00',NULL,''),(8,'2024-07-09 21:21:22','2024-07-11 17:38:28',1500,'Bike maintenance ','15','1','2024-07-07 20:31:00',NULL,''),(9,'2024-07-09 21:21:22','2024-07-11 17:38:37',2500,'Green Dress','16','1','2024-07-07 20:31:00',NULL,''),(10,'2024-07-09 21:21:22','2024-07-11 17:38:49',500,'Fresh Vegetables','7','1','2024-07-07 20:32:00',NULL,''),(11,'2024-07-09 21:21:22','2024-07-11 17:39:29',1800,'Dine out with Friends','5','1','2024-07-07 20:32:00',NULL,''),(12,'2024-07-09 21:21:22','2024-07-11 17:39:42',5000,'Kids School Fees','20','1','2024-07-07 20:33:00',NULL,''),(13,'2024-07-09 21:21:22','2024-07-11 17:48:55',2530,'Dairy Biscuits','30','1','2024-07-07 20:33:00','',''),(14,'2024-07-09 21:21:22','2024-07-11 17:40:03',2900,'Fresh Milk','30','1','2024-07-07 20:34:00',NULL,''),(15,'2024-07-09 21:21:22','2024-07-11 17:39:14',900,'Fruits','7','1','2024-07-07 20:34:00',NULL,''),(16,'2024-07-09 21:21:22','2024-07-11 17:40:13',1000,'Onions','6','1','2024-07-07 20:35:00',NULL,''),(17,'2024-07-09 21:21:22','2024-07-11 17:40:22',180,'Yogurt','30','1','2024-07-07 20:35:00',NULL,''),(18,'2024-07-09 21:21:22','2024-07-11 17:40:30',150,'Tomatoes','6','1','2024-07-07 20:35:00',NULL,''),(19,'2024-07-09 21:21:22','2024-07-11 17:40:36',200,'Bananas  ','7','1','2024-07-07 20:36:00',NULL,''),(20,'2024-07-09 21:21:22','2024-07-11 17:40:40',500,'Mangoes  ','7','1','2024-07-07 20:39:00',NULL,''),(21,'2024-07-09 21:21:22','2024-07-11 17:40:50',860,'Dairy Milk','30','1','2024-07-07 20:40:00',NULL,''),(22,'2024-07-09 21:21:22','2024-07-11 17:40:58',360,'Vegetables  ','6','1','2024-07-07 20:40:00',NULL,''),(23,'2024-07-09 21:21:22','2024-07-11 17:41:05',240,'Stationary Items','20','1','2024-07-07 20:42:00',NULL,''),(24,'2024-07-09 21:21:22','2024-07-11 17:41:12',140,'Cooking Oil','4','1','2024-07-07 20:46:00',NULL,''),(25,'2024-07-09 21:21:22','2024-07-11 17:41:23',50000,'Initial Amount','29','3','2024-07-09 17:52:00','',''),(26,'2024-07-09 21:21:22','2024-07-10 16:18:50',5000,'To Cash','31','3','2024-07-09 17:54:00','',''),(27,'2024-07-09 21:21:22','2024-07-10 16:18:58',5000,'From Saving','32','1','2024-07-09 17:55:00','',''),(28,'2024-07-09 21:21:22','2024-07-11 17:41:32',340,'Cold Drinks','4','1','2024-07-09 18:24:00',NULL,''),(29,'2024-07-09 21:21:22','2024-07-11 17:41:39',260,'Stationary Items','20','1','2024-07-09 18:26:00',NULL,''),(30,'2024-07-09 21:21:22','2024-07-11 17:48:37',180,'Biscuits and Bread ','4','1','2024-07-09 18:29:00','',''),(31,'2024-07-09 21:21:22','2024-07-11 17:41:58',190,'Apricots','7','1','2024-07-09 18:31:00',NULL,''),(32,'2024-07-09 21:21:22','2024-07-11 17:48:44',340,'Cold Drinks','4','1','2024-07-09 18:35:00','',''),(33,'2024-07-09 21:21:22','2024-07-11 17:42:11',30,'Misc','4','1','2024-07-09 18:36:00',NULL,''),(34,'2024-07-10 00:00:32','2024-07-11 17:42:17',3000,'Car Repair','14','1','2024-07-09 17:53:00','',''),(35,'2024-07-10 00:01:19','2024-07-11 17:42:26',850,'Oranges','7','1','2024-07-09 17:54:00','',''),(36,'2024-07-10 16:16:55','2024-07-11 17:42:36',300,'Bike Repair','14','1','2024-07-10 11:26:00','',''),(37,'2024-07-10 16:27:39','2024-07-11 17:44:47',600,'Charity','18','1','2024-07-10 12:26:00','',''),(38,'2024-07-10 16:28:08','2024-07-11 17:44:58',5500,'Cleaning Service','12','3','2024-07-10 12:26:00','',''),(39,'2024-07-10 18:36:26','2024-07-11 17:45:04',200,'Green Veges','6','1','2024-07-10 14:35:00','',''),(40,'2024-07-10 18:36:56','2024-07-11 17:45:23',900,'Milk Packs','30','1','2024-07-10 14:35:00','',''),(41,'2024-07-10 18:45:18','2024-07-11 17:45:28',180,'Yogurt','30','1','2024-07-10 18:45:00','',''),(42,'2024-07-10 18:46:52','2024-07-11 17:45:33',100,'Dine out','4','1','2024-07-10 18:46:00','',''),(45,'2024-07-10 23:02:34','2024-07-10 23:03:07',15000,'To Cash','31','3','2024-07-10 22:55:00','',''),(46,'2024-07-10 23:03:35','2024-07-10 23:03:43',15000,'From Saving','32','1','2024-07-10 23:03:00','',''),(47,'2024-07-10 23:04:01','2024-07-11 17:45:45',14350,'Grocery Mart','4','1','2024-07-10 23:03:00','',''),(48,'2024-07-10 23:04:22','2024-10-22 13:05:26',100,'Gift','18','1','2024-07-10 23:04:00','',''),(49,'2024-07-10 23:11:51','2024-07-11 17:46:08',570500,'initial','26','2','2024-07-10 23:11:00','',''),(50,'2024-07-10 23:12:35','2024-07-10 23:12:35',20000,'To Cash','31','2','2024-07-10 23:12:00','',''),(51,'2024-07-10 23:13:01','2024-08-19 17:04:54',20000,'From Bank','29','1','2024-07-10 23:12:00','',''),(52,'2024-07-10 23:14:26','2024-07-11 17:47:57',1300,'Sugar Packs','4','1','2024-07-10 23:14:00','',''),(53,'2024-07-10 23:14:53','2024-07-11 17:46:29',300,'Bread','5','1','2024-07-10 23:14:00','',''),(54,'2024-07-10 23:19:51','2024-07-11 17:46:35',100,'Gift','28','1','2024-07-10 23:19:00','','');
/*!40000 ALTER TABLE `tb_transactions` ENABLE KEYS */;
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
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2025-04-07 15:48:42
