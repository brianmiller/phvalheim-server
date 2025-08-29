-- MySQL dump 10.13  Distrib 8.2.0, for Win64 (x86_64)
--
-- Host: 127.0.0.1    Database: ct_donation_management
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
-- Current Database: `ct_donation_management`
--

CREATE DATABASE /*!32312 IF NOT EXISTS*/ `ct_donation_management` /*!40100 DEFAULT CHARACTER SET utf8 */;

USE `ct_donation_management`;

--
-- Table structure for table `tb_companies`
--

DROP TABLE IF EXISTS `tb_companies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tb_companies` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `name` varchar(255) DEFAULT NULL,
  `logo` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_companies`
--

LOCK TABLES `tb_companies` WRITE;
/*!40000 ALTER TABLE `tb_companies` DISABLE KEYS */;
INSERT INTO `tb_companies` VALUES (1,'2024-07-02 05:50:04','2024-07-02 06:37:29','Halibutron','https://picsum.photos/500/400?r=24'),(2,'2024-07-02 05:50:04','2024-07-02 06:37:29','Hot Take Media Group','https://picsum.photos/500/400?r=55'),(3,'2024-07-02 05:50:04','2024-07-02 06:37:29','Lyons, Tiger & Behr','https://picsum.photos/500/400?r=58'),(4,'2024-07-02 05:50:04','2024-07-02 06:37:29','Mansonto','https://picsum.photos/500/400?r=70'),(5,'2024-07-02 05:50:04','2024-07-02 06:37:29','The Ascalon Group','https://picsum.photos/500/400?r=9'),(6,'2024-07-02 05:50:04','2024-07-02 06:37:29','Triaria','https://picsum.photos/500/400?r=53');
/*!40000 ALTER TABLE `tb_companies` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tb_contacts`
--

DROP TABLE IF EXISTS `tb_contacts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tb_contacts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `name` varchar(255) DEFAULT NULL,
  `associated_companies` varchar(255) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `telephone` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `linkedin_profile` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=39 DEFAULT CHARSET=utf8;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_contacts`
--

LOCK TABLES `tb_contacts` WRITE;
/*!40000 ALTER TABLE `tb_contacts` DISABLE KEYS */;
INSERT INTO `tb_contacts` VALUES (20,'2024-07-02 06:59:50','2024-07-02 07:00:14','Aisha Harris','5','2381 Lazy Spring Circuit  No Mans Land, Oregon 97150-2962','(123) 456-7890','aisha@ascalon.example','https://www.linkedin.com/profile/fakeexample'),(21,'2024-07-02 06:59:50','2024-07-02 07:00:14','Andrés Diallo','6','4823 Dewy Goose Route  Success, New Hampshire 03434-1932','(123) 456-7890','andres@triaria.example','https://www.linkedin.com/profile/fakeexample'),(22,'2024-07-02 06:59:50','2024-07-02 07:00:14','Çakıl Demir',NULL,'1931 Honey Crossing  Serenity, Hawaii  96798-7660','(123) 456-7890','caki@emailmail.example','https://www.linkedin.com/profile/fakeexample'),(23,'2024-07-02 06:59:50','2024-07-02 07:00:14','Chester Gilmour',NULL,'9870 Easy Trace  Prince Albert, Mississippi  39651-1483','(123) 456-7890','chester@emailmail.example','https://www.linkedin.com/profile/fakeexample'),(24,'2024-07-02 06:59:50','2024-07-02 07:00:14','Corin Lafenestre',NULL,'8258 Fallen Branch Abbey  Hen Scratch, Arizona  85236-8745','(123) 456-7890','corin@emailmail.example','https://www.linkedin.com/profile/fakeexample'),(25,'2024-07-02 06:59:50','2024-07-02 07:00:14','Isabel Lopez','2','5733 Albermaler Lane Noicetown, Pennsylvania 13945','(123) 456-7890','isabel@hottake.example','https://www.linkedin.com/profile/fakeexample'),(26,'2024-07-02 06:59:50','2024-07-02 07:00:14','Karla Cohen','5','9551 Fallen Pine Cape  Nesquehoning, California  95922-2534','(123) 456-7890','karla@ascalon.example','https://www.linkedin.com/profile/fakeexample'),(27,'2024-07-02 06:59:50','2024-07-02 07:00:14','Katie Lacy','4','4507 Quiet Pines  Flint, California  94225-1360','(123) 456-7890','katie@mansonto.example','https://www.linkedin.com/profile/fakeexample'),(28,'2024-07-02 06:59:50','2024-07-02 07:00:14','Laura Bubnis','1','3636 Rustic Moor  Milton-Freewater, California  90954-4435','(123) 456-7890','laura@halibutron.example','https://www.linkedin.com/profile/fakeexample'),(29,'2024-07-02 06:59:50','2024-07-02 07:00:14','Laura Knox','3','8258 Fallen Branch Abbey  Hen Scratch, Arizona  85236-8745','(123) 456-7890','lknox@ltandb.example','https://www.linkedin.com/profile/fakeexample'),(30,'2024-07-02 06:59:50','2024-07-02 07:00:14','Laury Knox','2','4893 Little Lagoon Link  Marked Tree, California  91012-2015','(123) 456-7890','Laury@hottake.example','https://www.linkedin.com/profile/fakeexample'),(31,'2024-07-02 06:59:50','2024-07-02 07:00:14','Maple Mahnke',NULL,'1877 Quaking Creek Diversion  Magic Hollow, Indiana  46275-7106','(123) 456-7890','maple@ascalon.example','https://www.linkedin.com/profile/fakeexample'),(32,'2024-07-02 06:59:50','2024-07-02 07:00:14','Maria Kozlov',NULL,'4193 Misty Lookout  Thermopylae, Delaware 19953-2782','(123) 456-7890','maria@emailmail.example','https://www.linkedin.com/profile/fakeexample'),(33,'2024-07-02 06:59:50','2024-07-02 07:00:14','Mariel Escobedo','6','9950 Umber Rabbit Diversion Waterproof, California  91446-4096','(123) 456-7890','mariel@triaria.example','https://www.linkedin.com/profile/fakeexample'),(34,'2024-07-02 06:59:50','2024-07-02 07:00:14','Rob Chan','3','6433 Burning Valley  Valentine, California 96177-5570','(123) 456-7890','rchan@ltandb.example','https://www.linkedin.com/profile/fakeexample'),(35,'2024-07-02 06:59:50','2024-07-02 07:00:14','Shiori Okuda','6','4859 Pleasant Anchor Isle  Colts Neck, Mississippi  39164-9839','(123) 456-7890','shiori@triaria.example','https://www.linkedin.com/profile/fakeexample'),(36,'2024-07-02 06:59:50','2024-07-02 07:00:14','Shirin Rassul','4','8906 Emerald Arbor  Pleasantdale, California 90078-0199','(123) 456-7890','shirin@mansonto.example','https://www.linkedin.com/profile/fakeexample'),(37,'2024-07-02 06:59:50','2024-07-02 07:00:14','Sooyoung Ahn','5','2698 Colonial Butterfly Range  Tropic, Maine  04180-6012','(123) 456-7890','sooyoung@ascalon.example','https://www.linkedin.com/profile/fakeexample'),(38,'2024-07-02 06:59:50','2024-07-02 07:00:14','Sophie Grumbach',NULL,'5355 Golden Fawn Hill  Four Gums, Maine  04946-8407','(123) 456-7890','sophie@emailmail.example','https://www.linkedin.com/profile/fakeexample');
/*!40000 ALTER TABLE `tb_contacts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tb_designations`
--

DROP TABLE IF EXISTS `tb_designations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tb_designations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `name` varchar(255) DEFAULT NULL,
  `program_description` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_designations`
--

LOCK TABLES `tb_designations` WRITE;
/*!40000 ALTER TABLE `tb_designations` DISABLE KEYS */;
INSERT INTO `tb_designations` VALUES (1,'2024-07-02 05:56:46','2024-07-02 05:56:46','Kids Rec','We provide free after-school recreation programs between 3:30 and 6 p.m. We teach various sports and skills including soccer, hockey and karate. All our programs provide a safe, fun and positive environment where youth can participate in a variety of enri'),(2,'2024-07-02 05:56:46','2024-07-02 05:56:46','Homework Help','Homework help is provided through our volunteer network from 2-5 p.m. at select after-school locations.'),(3,'2024-07-02 05:56:46','2024-07-02 07:09:30','Unrestricted','Open for all purposes');
/*!40000 ALTER TABLE `tb_designations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tb_donations`
--

DROP TABLE IF EXISTS `tb_donations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tb_donations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `gift_id` varchar(255) DEFAULT NULL,
  `designated_program` varchar(255) DEFAULT NULL,
  `amount` varchar(255) DEFAULT NULL,
  `donor` varchar(255) DEFAULT NULL,
  `first_contacted` varchar(255) DEFAULT NULL,
  `date_of_donation` varchar(255) DEFAULT NULL,
  `thank_you_sent` varchar(255) DEFAULT NULL,
  `on_behalf_of_corporation` varchar(255) DEFAULT NULL,
  `donors_employer` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_donations`
--

LOCK TABLES `tb_donations` WRITE;
/*!40000 ALTER TABLE `tb_donations` DISABLE KEYS */;
INSERT INTO `tb_donations` VALUES (1,'2024-07-02 05:57:56','2024-07-02 07:07:03','5/4/2019—Andrés Diallo','2','575','21','2019-04-28','2019-05-04','checked','','6'),(2,'2024-07-02 05:57:56','2024-07-02 07:07:03','5/2/2019—Shiori Okuda','2','50000','35','2019-05-01','2019-05-02','checked','','6'),(3,'2024-07-02 05:57:56','2024-07-02 07:07:03','7/4/2019—Maple Mahnke','2','50','31','2019-07-03','2019-07-04','checked','',NULL),(4,'2024-07-02 05:57:56','2024-07-02 07:07:03','6/12/2018—Laury Knox','2','17500','30','2018-06-01','2018-06-12','','','2'),(5,'2024-07-02 05:57:56','2024-07-02 07:07:03','5/9/2019—Andrés Diallo','1','4954','21','2019-05-03','2019-05-09','checked','','6'),(6,'2024-07-02 05:57:56','2024-07-02 07:07:03','7/10/2019—Corin Lafenestre','1','56','24','2019-07-05','2019-07-10','','',NULL),(7,'2024-07-02 05:57:56','2024-07-02 07:07:03','6/4/2019—Chester Gilmour','1','13545','23','2019-06-01','2019-06-04','checked','',NULL),(8,'2024-07-02 05:57:56','2024-07-02 07:07:03','5/1/2019—Aisha Harris','3','650','20','2019-04-13','2019-05-01','checked','','5'),(9,'2024-07-02 05:57:56','2024-07-02 07:07:03','6/11/2019—Çakıl Demir','3','12454','22','2019-06-03','2019-06-11','checked','',NULL),(10,'2024-07-02 05:57:56','2024-07-02 07:07:03','6/28/2019—Sophie Grumbach','3','463','38','2019-06-26','2019-06-28','checked','',NULL),(11,'2024-07-02 05:57:56','2024-07-02 07:07:03','7/8/2019—Laury Knox','3','750','30','2019-07-01','2019-07-08','','','2'),(12,'2024-07-02 05:57:56','2024-07-02 07:07:03','5/2/2019—Rob Chan','3','7050','34','2019-04-21','2019-05-02','','checked',NULL),(13,'2024-07-02 05:57:56','2024-07-02 07:07:03','6/5/2018—Rob Chan','3','8500','34','2018-05-05','2018-06-05','','checked',NULL),(14,'2024-07-02 05:57:56','2024-07-02 07:07:03','6/30/2018—Laura Bubnis','3','2500','28','2018-06-17','2018-06-30','','checked','1'),(15,'2024-07-02 05:57:56','2024-07-02 07:07:03','6/10/2018—Laury Knox','3','4000','30','2018-06-01','2018-06-10','','','2'),(16,'2024-07-02 05:57:56','2024-07-02 07:07:03','7/1/2018—Katie Lacy','3','500','27','2018-06-18','2018-07-01','','checked','4'),(17,'2024-07-02 05:57:56','2024-07-02 07:07:03','6/26/2018—Laury Knox','3','1250','30','2018-05-31','2018-06-26','','checked','2'),(18,'2024-07-02 06:38:58','2024-07-02 07:07:21','07/02/2024—Rob Chan','1','100','34','2024-06-03','2024-07-02','1','0','1'),(19,'2025-02-12 14:17:25','2025-02-12 14:17:25','02/13/2025—Aisha Harris','1','','20',NULL,'2025-02-13','0','0','1');
/*!40000 ALTER TABLE `tb_donations` ENABLE KEYS */;
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
INSERT INTO `tb_settings` VALUES (1,'2025-03-16 08:11:48','2025-03-16 09:07:59','Application Name','app_name','Donation Management'),(2,'2025-03-16 08:11:48','2025-03-16 08:11:48','Enable Authentication','auth_enabled','0');
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
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2025-04-07 15:48:30
