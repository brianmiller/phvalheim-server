-- MySQL dump 10.13  Distrib 8.2.0, for Win64 (x86_64)
--
-- Host: 127.0.0.1    Database: ct_sales_crm
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
-- Current Database: `ct_sales_crm`
--

CREATE DATABASE /*!32312 IF NOT EXISTS*/ `ct_sales_crm` /*!40100 DEFAULT CHARACTER SET utf8 */;

USE `ct_sales_crm`;

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
  `industry` varchar(255) DEFAULT NULL,
  `size` varchar(255) DEFAULT NULL,
  `company_website` varchar(255) DEFAULT NULL,
  `company_linkedin` varchar(255) DEFAULT NULL,
  `hq_address` varchar(255) DEFAULT NULL,
  `phone` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=35 DEFAULT CHARSET=utf8;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_accounts`
--

LOCK TABLES `tb_accounts` WRITE;
/*!40000 ALTER TABLE `tb_accounts` DISABLE KEYS */;
INSERT INTO `tb_accounts` VALUES (18,'2024-06-24 01:00:28','2025-01-31 14:02:19','Abu Ghufran','Chemical','11-50','Abu Ghufran','Abu Ghufran','A299, SBCHS, BL-12, GULISTANE JOHAR','+923003589250'),(19,'2024-06-24 01:00:28','2024-06-24 07:28:40','Acetube','Chemical','101-500','https://www.example.com','http://linkedin.com/in/thisisanexample','Tte Luis Uribe 6000, San Miguel, Región Metropolitana, Chile',NULL),(20,'2024-06-24 01:00:28','2024-06-24 07:28:40','Bear Paw Solutions','Insurance','101-500','https://www.example.com','http://linkedin.com/in/thisisanexample','485 Zoe Street, San Francisco, CA 94107',NULL),(21,'2024-06-24 01:00:28','2024-06-24 07:28:40','Eagle Food Centers','Retail','1-10','https://www.example.com','http://linkedin.com/in/thisisanexample','',NULL),(22,'2024-06-24 01:00:28','2024-06-24 07:28:40','Edge Yard Service','Consumer goods','51-100','https://www.example.com','http://linkedin.com/in/thisisanexample','',NULL),(23,'2024-06-24 01:00:28','2024-06-24 07:28:40','Elek-Tek','Telecommunications','101-500','https://www.example.com','http://linkedin.com/in/thisisanexample','',NULL),(24,'2024-06-24 01:00:28','2024-06-24 07:28:40','Galerprises','Energy','101-500','https://www.example.com','http://linkedin.com/in/thisisanexample','Siesmayerstraße 777, 60323 Frankfurt am Main, Germany',NULL),(25,'2024-06-24 01:00:28','2024-06-24 07:28:40','Huyler\'s','Insurance','10000+','https://www.example.com','http://linkedin.com/in/thisisanexample','',NULL),(26,'2024-06-24 01:00:28','2024-06-24 07:28:40','Jay Jacobs','Information technology','11-50','https://www.example.com','http://linkedin.com/in/thisisanexample','1093 Duffy St Fort Myers, FL 33916',NULL),(27,'2024-06-24 01:00:28','2024-06-24 07:28:40','Leonard Krower & Sons','Insurance','501-1000','https://www.example.com','http://linkedin.com/in/thisisanexample','',NULL),(28,'2024-06-24 01:00:28','2024-06-24 07:28:40','Owlimited','Retail','1000-5000','https://www.example.com','http://linkedin.com/in/thisisanexample','99 Wolfe Tone St North City Dublin 1 D01 ED36 Ireland',NULL),(29,'2024-06-24 01:00:28','2024-06-24 07:28:40','Payless Cashways','Banking','5000-10000','https://www.example.com','http://linkedin.com/in/thisisanexample','',NULL),(30,'2024-06-24 01:00:28','2024-06-24 07:28:40','Revelationetworks','Telecommunications','5000-10000','https://www.example.com','http://linkedin.com/in/thisisanexample','454-1 Ajax Way, Pinelands, Cape Town, 7405, South Africa',NULL),(31,'2024-06-24 01:00:28','2024-06-24 07:28:40','Robinetworks','Telecommunications','501-1000','https://www.example.com','http://linkedin.com/in/thisisanexample','10000 NE Sammy Lane, Kansas City, MO',NULL),(32,'2024-06-24 01:00:28','2024-06-24 07:28:40','Sunlight Intelligence','Publishing','51-100','https://www.example.com','http://linkedin.com/in/thisisanexample','31 Cawthra Square, Toronto, ON M4Y',NULL),(33,'2024-06-24 01:00:28','2024-06-24 07:28:40','Timbershadow','Consumer goods','5000-10000','https://www.example.com','http://linkedin.com/in/thisisanexample','',NULL),(34,'2024-06-24 01:00:28','2024-06-24 07:28:40','Wolf Motors','Automotive','501-1000','https://www.example.com','http://linkedin.com/in/thisisanexample','55 Star Lane, Boulder, CO, 80301',NULL);
/*!40000 ALTER TABLE `tb_accounts` ENABLE KEYS */;
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
  `name_and_organization` varchar(255) DEFAULT NULL,
  `account` varchar(255) DEFAULT NULL,
  `vip` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(255) DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `department` varchar(255) DEFAULT NULL,
  `linkedin` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=40 DEFAULT CHARSET=utf8;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_contacts`
--

LOCK TABLES `tb_contacts` WRITE;
/*!40000 ALTER TABLE `tb_contacts` DISABLE KEYS */;
INSERT INTO `tb_contacts` VALUES (20,'2024-06-24 06:37:24','2024-06-29 08:22:26','Rose Fowler','Rose Fowler  —  Bear Paw Solutions','20','','rose@example.com','(123) 456-7890','Marketing manager','Marketing','http://linkedin.com/in/thisisanexample'),(21,'2024-06-24 06:37:24','2024-06-29 08:22:26','Michelle Torres','Michelle Torres  —  Sunlight Intelligence','32','checked','michelle@example.com','(123) 456-7890','Director of EMEA supply chain','EMEA operations','http://linkedin.com/in/thisisanexample'),(22,'2024-06-24 06:37:24','2024-06-29 08:22:26','Billy Bennett','Billy Bennett — Wolf Motors','34','','billy@example.com','(123) 456-7890','Design lead','Design','http://linkedin.com/in/thisisanexample'),(23,'2024-06-24 06:37:24','2024-06-29 08:22:26','Judith May','Judith May — Robinetworks','31','checked','judith@example.com','(123) 456-7890','Head of customer success','Customer success','http://linkedin.com/in/thisisanexample'),(24,'2024-06-24 06:37:24','2024-06-29 08:22:26','Olivia Burton','Olivia Burton — Owlimited','28','','olivia@example.com','(123) 456-7890','CHRO','Human resources','http://linkedin.com/in/thisisanexample'),(25,'2024-06-24 06:37:24','2024-06-29 08:22:26','Judith Clark','Judith Clark — Galerprises','24','','judith@example.com','(123) 456-7890','Computer control programmer','Marketing','http://linkedin.com/in/thisisanexample'),(26,'2024-06-24 06:37:24','2024-06-29 08:22:26','Mildred Weber','Mildred Weber — Revelationetworks','30','','mildred@example.com','(123) 456-7890','Music instructor','EMEA operations','http://linkedin.com/in/thisisanexample'),(27,'2024-06-24 06:37:24','2024-06-29 08:22:26','Victoria Porter','Victoria Porter — Acetube','19','','victoria@example.com','(123) 456-7890','Paper goods machine setter','Design','http://linkedin.com/in/thisisanexample'),(28,'2024-06-24 06:37:24','2024-06-29 08:22:26','Eric Jackson','Eric Jackson — Acepoly','18','','eric@example.com','(123) 456-7890','Pesticide applicator','Customer success','http://linkedin.com/in/thisisanexample'),(29,'2024-06-24 06:37:24','2024-06-29 08:22:26','Scott Brewer','Scott Brewer — Timbershadow','33','','scott@example.com','(123) 456-7890','Deputy sheriff','Human resources','http://linkedin.com/in/thisisanexample'),(30,'2024-06-24 06:37:24','2024-06-29 08:22:26','Richard Chen','Richard Chen — Jay Jacobs','26','','richard@example.com','(123) 456-7890','Chemical engineering technician','Marketing','http://linkedin.com/in/thisisanexample'),(31,'2024-06-24 06:37:24','2024-06-29 08:22:26','Olivia Guzman','Olivia Guzman — Edge Yard Service','22','','olivia@example.com','(123) 456-7890','Management consultant','EMEA operations','http://linkedin.com/in/thisisanexample'),(32,'2024-06-24 06:37:24','2024-06-29 08:22:26','Theresa Griffin','Theresa Griffin — Elek-Tek','23','','theresa@example.com','(123) 456-7890','Technical support specialist','Design','http://linkedin.com/in/thisisanexample'),(33,'2024-06-24 06:37:24','2024-06-29 08:22:26','Jeffrey Grant','Jeffrey Grant — Payless Cashways','29','','jeffrey@example.com','(123) 456-7890','Crane and tower operator','Customer success','http://linkedin.com/in/thisisanexample'),(34,'2024-06-24 06:37:24','2024-06-29 08:22:26','Helen Ryan','Helen Ryan — Eagle Food Centers','21','','helen@example.com','(123) 456-7890','Prison guard','Human resources','http://linkedin.com/in/thisisanexample'),(35,'2024-06-24 06:37:24','2024-06-29 08:22:26','Lori Dixon','Lori Dixon — Leonard Krower & Sons','27','','lori@example.com','(123) 456-7890','Photographic equipment repairer','Marketing','http://linkedin.com/in/thisisanexample'),(36,'2024-06-24 06:37:24','2024-06-29 08:22:26','Pamela Jimenez','Pamela Jimenez — Huyler\'s','25','','pamela@example.com','(123) 456-7890','Safety inspector','EMEA operations','http://linkedin.com/in/thisisanexample'),(37,'2024-06-24 06:37:24','2024-06-29 08:22:26','Jonathan Burke','Jonathan Burke — Leonard Krower & Sons','27','','jonathan@example.com','(123) 456-7890','Geophysical prospecting surveyor','Design','http://linkedin.com/in/thisisanexample'),(38,'2024-06-24 06:37:24','2024-06-29 08:22:26','Lauren Chavez','Lauren Chavez — Galerprises','24','checked','lauren@example.com','(123) 456-7890','Personnel services specialist','Customer success','http://linkedin.com/in/thisisanexample'),(39,'2024-06-24 10:19:34','2024-06-24 10:19:34','Abu Ghufran','','18','1','gridphp@gmail.com','+923003589250','Product Manager','Marketing','');
/*!40000 ALTER TABLE `tb_contacts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tb_interactions`
--

DROP TABLE IF EXISTS `tb_interactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tb_interactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `interaction` varchar(255) DEFAULT NULL,
  `type` varchar(255) DEFAULT NULL,
  `date` varchar(255) DEFAULT NULL,
  `status` varchar(255) DEFAULT NULL,
  `opportunity` varchar(255) DEFAULT NULL,
  `contact` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=58 DEFAULT CHARSET=utf8;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_interactions`
--

LOCK TABLES `tb_interactions` WRITE;
/*!40000 ALTER TABLE `tb_interactions` DISABLE KEYS */;
INSERT INTO `tb_interactions` VALUES (1,'2024-06-24 00:31:29','2024-07-11 00:27:19','Acepoly AUS — Discovery','Discovery','2024-07-07 23:46:00','Negotiation','74','28'),(2,'2024-06-24 00:31:29','2024-06-29 08:24:14','Acepoly AUS — Demo','Demo','2020-06-09 23:30:00','Negotiation','74','28'),(3,'2024-06-24 00:31:29','2024-06-29 08:24:14','Acepoly AUS — Pricing discussion','Pricing discussion','2020-06-18 16:30:00','Negotiation','74','28'),(4,'2024-06-24 00:31:29','2024-06-29 08:24:14','Acepoly AUS — Legal discussion','Legal discussion','2020-06-24 18:00:00','Negotiation','74','28'),(5,'2024-06-24 00:31:29','2024-06-29 08:24:14','Acetube inquiry — Discovery','Discovery','2020-08-04 02:00:00','Qualification','59','27'),(6,'2024-06-24 00:31:29','2024-06-29 08:24:14','Acetube inquiry — Demo','Demo','2020-08-14 23:00:00','Qualification','59','27'),(7,'2024-06-24 00:31:29','2024-06-29 08:24:14','BPS Pilot — Discovery','Discovery','2020-08-12 19:30:00','Qualification','57','20'),(8,'2024-06-24 00:31:29','2024-06-29 08:24:14','BPS Pilot — Pricing discussion','Pricing discussion','2020-08-22 01:30:00','Qualification','57','20'),(9,'2024-06-24 00:31:29','2024-06-29 08:24:14','BPS second use case — Discovery','Discovery','2020-07-20 22:00:00','Proposal','61','20'),(10,'2024-06-24 00:31:29','2024-06-29 08:24:14','BPS second use case — Legal discussion','Legal discussion','2020-08-19 01:00:00','Proposal','61','20'),(11,'2024-06-24 00:31:29','2024-06-29 08:24:14','EFC inbound — Discovery','Discovery','2020-06-24 02:00:00','Evaluation','68','34'),(12,'2024-06-24 00:31:29','2024-06-29 08:24:14','EFC inbound — Demo','Demo','2020-07-10 20:00:00','Evaluation','68','34'),(13,'2024-06-24 00:31:29','2024-06-29 08:24:14','EFC inbound — Pricing discussion','Pricing discussion','2020-07-17 21:00:00','Evaluation','68','34'),(14,'2024-06-24 00:31:29','2024-06-29 08:24:14','ET pilot — Discovery','Discovery','2020-06-21 00:00:00','Negotiation','75','32'),(15,'2024-06-24 00:31:29','2024-06-29 08:24:14','ET pilot — Demo','Demo','2020-07-01 19:00:00','Negotiation','75','32'),(16,'2024-06-24 00:31:29','2024-06-29 08:24:14','ET pilot — Legal discussion','Legal discussion','2020-07-09 18:30:00','Negotiation','75','32'),(17,'2024-06-24 00:31:29','2024-06-29 08:24:14','Galerprises exploratory — Discovery','Discovery','2020-07-01 20:30:00','Negotiation','73','25'),(18,'2024-06-24 00:31:29','2024-06-29 08:24:14','Galerprises exploratory — Demo','Demo','2020-07-07 22:30:00','Negotiation','73','25'),(19,'2024-06-24 00:31:29','2024-06-29 08:24:14','Galerprises exploratory — Pricing discussion','Pricing discussion','2020-07-14 18:00:00','Negotiation','73','25'),(20,'2024-06-24 00:31:29','2024-06-29 08:24:14','Galerprises exploratory — Legal discussion','Legal discussion','2020-07-20 23:30:00','Negotiation','73','25'),(21,'2024-06-24 00:31:29','2024-06-29 08:24:14','Galerprises renewal — Discovery','Discovery','2020-07-09 17:00:00','Proposal','66','38'),(22,'2024-06-24 00:31:29','2024-06-29 08:24:14','Galerprises renewal — Demo','Demo','2020-07-24 22:00:00','Proposal','66','38'),(23,'2024-06-24 00:31:29','2024-06-29 08:24:14','Galerprises renewal — Pricing discussion','Pricing discussion','2020-07-31 20:00:00','Proposal','66','38'),(24,'2024-06-24 00:31:29','2024-06-29 08:24:14','Galerprises second use case — Discovery','Discovery','2020-05-03 00:00:00','Closed—lost','82','25'),(25,'2024-06-24 00:31:29','2024-06-29 08:24:14','Galerprises second use case — Pricing discussion','Pricing discussion','2020-06-03 23:00:00','Closed—lost','82','25'),(26,'2024-06-24 00:31:29','2024-06-29 08:24:14','Huyler inquiry — Discovery','Discovery','2020-05-16 00:00:00','Closed—won','79','36'),(27,'2024-06-24 00:31:29','2024-06-29 08:24:14','Huyler inquiry — Demo','Demo','2020-06-05 23:00:00','Closed—won','79','36'),(28,'2024-06-24 00:31:29','2024-06-29 08:24:14','Huyler main team — Discovery','Discovery','2020-06-22 18:30:00','Evaluation','69','36'),(29,'2024-06-24 00:31:29','2024-06-29 08:24:14','JJ RFI — Discovery','Discovery','2020-06-19 23:00:00','Evaluation','70','30'),(30,'2024-06-24 00:31:29','2024-06-29 08:24:14','JJ RFI — Legal discussion','Legal discussion','2020-07-16 19:00:00','Evaluation','70','30'),(31,'2024-06-24 00:31:29','2024-06-29 08:24:14','JJ second team — Discovery','Discovery','2020-03-25 00:00:00','Closed—won','80','30'),(32,'2024-06-24 00:31:29','2024-06-29 08:24:14','JJ second team — Demo','Demo','2020-04-22 19:00:00','Closed—won','80','30'),(33,'2024-06-24 00:31:29','2024-06-29 08:24:14','LKS req — Discovery','Discovery','2020-08-06 22:00:00','Qualification','60','35'),(34,'2024-06-24 00:31:29','2024-06-29 08:24:14','LKS req — Pricing discussion','Pricing discussion','2020-08-26 23:30:00','Qualification','60','35'),(35,'2024-06-24 00:31:29','2024-06-29 08:24:14','LKS req — Demo','Demo','2020-08-11 18:30:00','Qualification','60','35'),(36,'2024-06-24 00:31:29','2024-06-29 08:24:14','Owlimited expansion — Discovery','Discovery','2020-04-03 00:00:00','Closed—won','81','24'),(37,'2024-06-24 00:31:29','2024-06-29 08:24:14','Owlimited expansion — Demo','Demo','2020-04-27 19:00:00','Closed—won','81','24'),(38,'2024-06-24 00:31:29','2024-06-29 08:24:14','Owlimited expansion — Legal discussion','Legal discussion','2020-05-04 22:00:00','Closed—won','81','24'),(39,'2024-06-24 00:31:29','2024-06-29 08:24:14','Owlimited inbound req — Discovery','Discovery','2020-05-25 18:00:00','Negotiation','76','24'),(40,'2024-06-24 00:31:29','2024-06-29 08:24:14','Owlimited inbound req — Pricing discussion','Pricing discussion','2020-06-24 22:30:00','Negotiation','76','24'),(41,'2024-06-24 00:31:29','2024-06-29 08:24:14','Payless Cashways EU — Discovery','Discovery','2020-06-26 18:00:00','Proposal','67','33'),(42,'2024-06-24 00:31:29','2024-06-29 08:24:14','Payless Cashways EU — Legal discussion','Legal discussion','2020-07-29 21:30:00','Proposal','67','33'),(43,'2024-06-24 00:31:29','2024-06-29 08:24:14','Payless Cashways EU — Demo','Demo','2020-07-15 20:00:00','Proposal','67','33'),(44,'2024-06-24 00:31:29','2024-06-29 08:24:14','RN RFQ — Discovery','Discovery','2020-05-17 01:00:00','Closed—lost','84','26'),(45,'2024-06-24 00:31:29','2024-06-29 08:24:14','Robinetworks renewal — Discovery','Discovery','2020-07-18 00:00:00','Proposal','63','23'),(46,'2024-06-24 00:31:29','2024-06-29 08:24:14','Robinetworks renewal — Demo','Demo','2020-08-05 20:00:00','Proposal','63','23'),(47,'2024-06-24 00:31:29','2024-06-29 08:24:14','Robinetworks RFQ — Discovery','Discovery','2020-04-01 00:00:00','Closed—lost','83','23'),(48,'2024-06-24 00:31:29','2024-06-29 08:24:14','Robinetworks RFQ — Demo','Demo','2020-04-23 21:00:00','Closed—lost','83','23'),(49,'2024-06-24 00:31:29','2024-06-29 08:24:14','Robinetworks RFQ — Pricing discussion','Pricing discussion','2020-05-03 01:45:00','Closed—lost','83','23'),(50,'2024-06-24 00:31:29','2024-06-29 08:24:14','SI expansion — Discovery','Discovery','2020-07-29 00:00:00','Proposal','62','21'),(51,'2024-06-24 00:31:29','2024-06-29 08:24:14','Sunlight renewal — Discovery','Discovery','2020-05-01 00:30:00','Closed—won','77','21'),(52,'2024-06-24 00:31:29','2024-06-29 08:24:14','Timbershadow expansion — Discovery','Discovery','2020-08-13 21:00:00','Qualification','58','29'),(53,'2024-06-24 00:31:29','2024-06-29 08:24:14','Timbershadow expansion — Demo','Demo','2020-08-25 01:00:00','Qualification','58','29'),(54,'2024-06-24 00:31:29','2024-06-29 08:24:14','Wolf RFI — Discovery','Discovery','2020-06-24 20:30:00','Evaluation','71','22'),(55,'2024-06-24 00:31:29','2024-06-29 08:24:14','Wolf RFI — Demo','Demo','2020-07-14 22:00:00','Evaluation','71','22'),(56,'2024-06-29 07:35:38','2024-06-29 07:35:38','RN RFQ-Discovery','Discovery','2024-06-18 00:00:00',NULL,'84','22'),(57,'2024-06-29 07:35:59','2024-06-29 08:29:05','BPS Pilot—Demo','Demo','2024-06-28 00:00:00',NULL,'57','21');
/*!40000 ALTER TABLE `tb_interactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tb_opportunities`
--

DROP TABLE IF EXISTS `tb_opportunities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tb_opportunities` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `opportunity_name` varchar(255) DEFAULT NULL,
  `status` varchar(255) DEFAULT NULL,
  `priority` varchar(255) DEFAULT NULL,
  `owner` varchar(255) DEFAULT NULL,
  `account` varchar(255) DEFAULT NULL,
  `primary_contact` varchar(255) DEFAULT NULL,
  `expected_close_date` varchar(255) DEFAULT NULL,
  `last_contact` varchar(255) DEFAULT NULL,
  `interactions` varchar(255) DEFAULT NULL,
  `estimated_value` decimal(10,2) DEFAULT NULL,
  `proposal_deadline` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=85 DEFAULT CHARSET=utf8;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_opportunities`
--

LOCK TABLES `tb_opportunities` WRITE;
/*!40000 ALTER TABLE `tb_opportunities` DISABLE KEYS */;
INSERT INTO `tb_opportunities` VALUES (57,'2024-06-23 19:26:20','2024-06-24 08:53:05','BPS Pilot','Qualification','Medium','Jess Patel','20','20','2020-08-19','8/21/2020','8,7',10000.00,''),(58,'2024-06-23 19:26:20','2024-06-24 07:13:30','Timbershadow expansion','Qualification','Very high','Casey Park','33','29','','8/24/2020','53,52',6154.00,''),(59,'2024-06-23 19:26:20','2024-06-24 19:17:37','Acetube inquiry','Qualification','Medium','Ari Ramírez-Medina','19','27',NULL,'8/14/2020','5,6',1500.00,NULL),(60,'2024-06-23 19:26:20','2024-06-24 07:13:30','LKS req','Qualification','Medium','Casey Park','27','35','','8/26/2020','35,34,33',9767.00,''),(61,'2024-06-23 19:26:20','2024-06-24 08:53:05','BPS second use case','Proposal','Very low','Sandy Hagen','20','20','2020-08-27','8/18/2020','10,9',24791.00,'2020-08-03'),(62,'2024-06-23 19:26:20','2024-06-24 09:23:41','SI expansion','Proposal','Low','Casey Park','32','21','2020-08-19','7/28/2020','50',12687.00,'2020-07-28'),(63,'2024-06-23 19:26:20','2024-06-24 08:53:05','Robinetworks renewal','Proposal','Medium','Sandy Hagen','31','23','2020-08-17','8/5/2020','46,45',24692.00,'2020-07-29'),(64,'2024-06-23 19:26:20','2024-06-24 08:53:05','Payless inbound','Proposal','Low','Ari Ramírez-Medina','29','38','2020-08-12','','',20999.00,'2020-07-23'),(65,'2024-06-23 19:26:20','2024-06-24 08:53:05','EYS renewal','Proposal','Low','Jess Patel','22','31','2020-08-10','','',23503.00,'2020-07-15'),(66,'2024-06-23 19:26:20','2024-06-24 08:53:05','Galerprises renewal','Proposal','Low','Jess Patel','24','25','2020-08-08','7/31/2020','23,22,21',23205.00,'2020-07-20'),(67,'2024-06-23 19:26:20','2024-06-24 08:53:05','Payless Cashways EU','Proposal','High','Jess Patel','29','33','2020-08-05','7/29/2020','43,42,41',15953.00,'2020-07-08'),(68,'2024-06-23 19:26:20','2024-06-24 08:53:05','EFC inbound','Evaluation','Medium','Casey Park','21','34','2020-07-24','7/17/2020','13,12,11',18714.00,'2020-07-06'),(69,'2024-06-23 19:26:20','2024-06-24 08:53:05','Huyler main team','Evaluation','Very high','Casey Park','25','36','2020-08-01','6/22/2020','28',20539.00,'2020-06-29'),(70,'2024-06-23 19:26:20','2024-06-24 08:53:05','JJ RFI','Evaluation','High','Logan Grandmont','26','30','2020-07-27','7/16/2020','30,29',7180.00,'2020-06-27'),(71,'2024-06-23 19:26:20','2024-06-24 08:53:05','Wolf RFI','Evaluation','High','Casey Park','34','22','2020-07-21','7/14/2020','55,54',17252.00,'2020-07-02'),(72,'2024-06-23 19:26:20','2024-06-24 08:53:05','Acepoly first team','Evaluation','High','Ari Ramírez-Medina','18','28','2020-07-30','','',6615.00,'2020-07-01'),(73,'2024-06-23 19:26:20','2024-06-24 08:53:05','Galerprises exploratory','Negotiation','Very low','Jess Patel','24','38','2020-07-28','7/20/2020','20,19,18,17',10501.00,'2020-07-09'),(74,'2024-06-23 19:26:20','2024-06-24 08:53:05','Acepoly AUS','Negotiation','Very high','Logan Grandmont','18','28','2020-07-01','6/24/2020','4,3,2,1',22024.00,'2020-05-28'),(75,'2024-06-23 19:26:20','2024-06-24 08:53:05','ET pilot','Negotiation','Very low','Sandy Hagen','23','32','2020-07-17','7/9/2020','16,15,14',16616.00,'2020-06-26'),(76,'2024-06-23 19:26:20','2024-06-24 08:53:05','Owlimited inbound req','Negotiation','High','Logan Grandmont','28','24','2020-07-02','6/24/2020','40,39',17899.00,'2020-06-04'),(77,'2024-06-23 19:26:20','2024-06-24 08:53:05','Sunlight renewal','Closed—won','Very high','Ari Ramírez-Medina','32','21','2020-06-08','4/30/2020','51',17573.00,'2020-05-01'),(78,'2024-06-23 19:26:20','2024-06-24 08:53:05','Acepolly second use case','Closed—won','Medium','Casey Park','18','28','2020-06-24','','',18049.00,'2020-05-29'),(79,'2024-06-23 19:26:20','2024-06-24 08:53:05','Huyler inquiry','Closed—won','High','Logan Grandmont','25','36,37','2020-06-10','6/5/2020','27,26',15161.00,'2020-05-18'),(80,'2024-06-23 19:26:20','2024-06-24 08:53:05','JJ second team','Closed—won','Very high','Casey Park','26','30','2020-05-08','4/22/2020','32,31',20068.00,'2020-03-30'),(81,'2024-06-23 19:26:20','2024-06-24 08:53:05','Owlimited expansion','Closed—won','Medium','Sandy Hagen','28','24','2020-05-11','5/4/2020','38,37,36',21304.00,'2020-04-09'),(82,'2024-06-23 19:26:20','2024-06-24 08:53:05','Galerprises second use case','Closed—lost','High','Ari Ramírez-Medina','24','38','2020-06-12','6/3/2020','25,24',23833.00,'2020-05-13'),(83,'2024-06-23 19:26:20','2024-06-24 08:53:05','Robinetworks RFQ','Closed—lost','Low','Casey Park','31','23','2020-05-15','5/2/2020','49,48,47',20036.00,'2020-04-16'),(84,'2024-06-23 19:26:20','2024-06-24 08:53:05','RN RFQ','Closed—lost','Very low','Sandy Hagen','30','26','2020-06-20','5/16/2020','44',18443.00,'2020-05-23');
/*!40000 ALTER TABLE `tb_opportunities` ENABLE KEYS */;
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
INSERT INTO `tb_settings` VALUES (1,'2025-03-16 08:11:48','2025-03-16 08:15:41','Application Name','app_name','Sales CRM'),(2,'2025-03-16 08:11:48','2025-03-27 12:11:34','Enable Authentication','auth_enabled','yes');
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
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_users`
--

LOCK TABLES `tb_users` WRITE;
/*!40000 ALTER TABLE `tb_users` DISABLE KEYS */;
INSERT INTO `tb_users` VALUES (1,'2025-03-16 08:11:47','2025-03-16 08:11:47','Admin User','admin','$2y$10$r8ZZVSglKuifADO5CRxiGuguuE6pwJcrU.Y72UeRLhGoeeozXDQuG','admin','active'),(3,'2025-03-27 12:23:19','2025-03-27 12:23:19','Guest User','guest@test.com','$2y$10$6zOPTrip3J3BtKCbmeAen.KTx5JlQdcJOz9sYwSBggJDprm5m68vq','readonly','active');
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

-- Dump completed on 2025-04-07 15:48:14
