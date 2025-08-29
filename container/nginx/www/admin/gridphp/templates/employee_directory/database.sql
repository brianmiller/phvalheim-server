-- MySQL dump 10.13  Distrib 8.2.0, for Win64 (x86_64)
--
-- Host: 127.0.0.1    Database: ct_employee_directory
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
-- Current Database: `ct_employee_directory`
--

CREATE DATABASE /*!32312 IF NOT EXISTS*/ `ct_employee_directory` /*!40100 DEFAULT CHARACTER SET utf8 */;

USE `ct_employee_directory`;

--
-- Table structure for table `tb_departments`
--

DROP TABLE IF EXISTS `tb_departments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tb_departments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `name` varchar(25) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_departments`
--

LOCK TABLES `tb_departments` WRITE;
/*!40000 ALTER TABLE `tb_departments` DISABLE KEYS */;
INSERT INTO `tb_departments` VALUES (1,'2025-02-11 11:28:31','2025-02-11 11:28:31','Engineering'),(2,'2025-02-11 11:28:31','2025-02-11 11:28:31','Design'),(3,'2025-02-11 11:28:31','2025-02-11 11:28:31','Product marketing'),(4,'2025-02-11 11:28:31','2025-03-09 17:03:25','Product management'),(5,'2025-02-11 11:28:31','2025-02-11 11:28:31','Sales'),(6,'2025-02-11 11:28:31','2025-02-11 11:28:31','Executive');
/*!40000 ALTER TABLE `tb_departments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tb_employees`
--

DROP TABLE IF EXISTS `tb_employees`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tb_employees` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `name` varchar(255) DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `status` varchar(255) DEFAULT NULL,
  `department` varchar(255) DEFAULT NULL,
  `reports_to` varchar(255) DEFAULT NULL,
  `email_address` varchar(255) DEFAULT NULL,
  `phone` varchar(255) DEFAULT NULL,
  `home_address` varchar(255) DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `dob` date DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=34 DEFAULT CHARSET=utf8;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_employees`
--

LOCK TABLES `tb_employees` WRITE;
/*!40000 ALTER TABLE `tb_employees` DISABLE KEYS */;
INSERT INTO `tb_employees` VALUES (24,'2025-02-12 00:49:01','2025-02-12 00:50:07','Sandy Hagen','New York City','https://picsum.photos/150/100?r=55','Product designer','Employee','2','25','shagen@example.com','(123) 456-7890','8691 Lafayette Court, Howard Beach, NY 11414','2018-02-01','1978-04-08'),(26,'2025-02-12 00:49:01','2025-03-10 02:57:25','irfan afnan','New York City','https://picsum.photos/150/100?r=70','CEO','Employee','6','24','peverett@example.com','(123) 456-7891','992 Summer Street, Jamaica, NY 11432','2014-08-24','1968-08-06'),(27,'2025-02-12 00:49:01','2025-02-12 00:50:07','Kai Siyavong','New York City','https://picsum.photos/150/100?r=9','PMM','Employee','3','25','ksiyavong@example.com','(123) 456-7890','595 Westfell Street, Brooklyn, NY 11237','2017-02-15','1983-02-09'),(28,'2025-02-12 00:49:01','2025-03-16 18:29:48','Robby Pritchet','San Francisco','https://picsum.photos/150/100?r=53','VP of engineering','Employee','1','27','rpritchett@example.com','(123) 456-7890','434 Fremont Ave, Fremont, CA 94555','2016-01-23','1953-06-17'),(29,'2025-02-12 00:49:01','2025-02-12 00:50:07','Dany Coronado','San Francisco','https://picsum.photos/150/100?r=99','Software engineer','Employee','1','28','dcoronado@example.com','(123) 456-7890','192 Kingsbury Lane, Palo Alto, CA 94323','2010-01-11','1989-10-11'),(30,'2025-02-12 00:49:01','2025-02-12 00:50:07','River Oe','San Francisco','https://picsum.photos/150/100?r=29','Product manager','Employee','4','25','riveroe@example.com','(123) 456-7890','490 Samuel Street, San Francisco, CA 94112','2011-12-30','1987-03-12'),(31,'2025-02-12 00:49:01','2025-02-12 00:50:07','Cameron Toth','San Francisco','https://picsum.photos/150/100?r=7','Software engineer','On leave','1','28','camerontoth@example.com','(123) 456-7890','99 Fale Road, San Francisco, CA 94123','2012-03-15','1981-11-24'),(32,'2025-02-12 00:49:01','2025-03-09 23:57:51','ghufran afnan ','San Francisco','https://picsum.photos/150/100?r=64','Account executive','Employee','5','25','tsoepa@example.com','(123) 456-7890','12 Paloma Road, San Francisco, CA 94103','2018-02-01','1964-05-04'),(33,'2025-02-12 00:49:01','2025-02-12 00:50:07','Sam Epps','San Francisco','https://picsum.photos/150/100?r=63','PMM','Employee','3','25','samepps@example.com','(123) 456-7890','16 Walnut Avenue, Palo Alto, CA 94028','2015-09-10','1986-09-28');
/*!40000 ALTER TABLE `tb_employees` ENABLE KEYS */;
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
INSERT INTO `tb_settings` VALUES (1,'2025-03-16 08:11:48','2025-03-16 08:17:25','Application Name','app_name','Employee Directory'),(2,'2025-03-16 08:11:48','2025-03-16 08:11:48','Enable Authentication','auth_enabled','0');
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

-- Dump completed on 2025-04-07 15:49:02
