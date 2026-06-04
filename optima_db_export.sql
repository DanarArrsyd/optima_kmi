-- MariaDB dump 10.19  Distrib 10.4.28-MariaDB, for osx10.10 (x86_64)
--
-- Host: localhost    Database: optima_db
-- ------------------------------------------------------
-- Server version	10.4.28-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `activity_log`
--

DROP TABLE IF EXISTS `activity_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `activity_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `log_name` varchar(255) DEFAULT NULL,
  `description` text NOT NULL,
  `subject_type` varchar(255) DEFAULT NULL,
  `subject_id` bigint(20) unsigned DEFAULT NULL,
  `event` varchar(255) DEFAULT NULL,
  `causer_type` varchar(255) DEFAULT NULL,
  `causer_id` bigint(20) unsigned DEFAULT NULL,
  `attribute_changes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`attribute_changes`)),
  `properties` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`properties`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `subject` (`subject_type`,`subject_id`),
  KEY `causer` (`causer_type`,`causer_id`),
  KEY `activity_log_log_name_index` (`log_name`)
) ENGINE=InnoDB AUTO_INCREMENT=61 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `activity_log`
--

LOCK TABLES `activity_log` WRITE;
/*!40000 ALTER TABLE `activity_log` DISABLE KEYS */;
INSERT INTO `activity_log` VALUES (1,'master_losstime','created','App\\Models\\MasterLosstime',1,'created',NULL,NULL,'{\"attributes\":{\"id\":1,\"kode\":\"1\",\"jenis_losstime\":\"Mesin_Rusak\",\"std_menit_default\":null,\"std_menit_progressive\":null,\"deleted_at\":null,\"created_at\":\"2026-05-23T13:49:06.000000Z\",\"updated_at\":\"2026-05-23T13:49:06.000000Z\"}}','[]','2026-05-23 06:49:06','2026-05-23 06:49:06'),(2,'master_losstime','created','App\\Models\\MasterLosstime',2,'created',NULL,NULL,'{\"attributes\":{\"id\":2,\"kode\":\"2\",\"jenis_losstime\":\"Tunggu_Crane\",\"std_menit_default\":null,\"std_menit_progressive\":null,\"deleted_at\":null,\"created_at\":\"2026-05-23T13:49:06.000000Z\",\"updated_at\":\"2026-05-23T13:49:06.000000Z\"}}','[]','2026-05-23 06:49:06','2026-05-23 06:49:06'),(3,'master_losstime','created','App\\Models\\MasterLosstime',3,'created',NULL,NULL,'{\"attributes\":{\"id\":3,\"kode\":\"3\",\"jenis_losstime\":\"Tunggu_Material\",\"std_menit_default\":null,\"std_menit_progressive\":null,\"deleted_at\":null,\"created_at\":\"2026-05-23T13:49:06.000000Z\",\"updated_at\":\"2026-05-23T13:49:06.000000Z\"}}','[]','2026-05-23 06:49:06','2026-05-23 06:49:06'),(4,'master_losstime','created','App\\Models\\MasterLosstime',4,'created',NULL,NULL,'{\"attributes\":{\"id\":4,\"kode\":\"4\",\"jenis_losstime\":\"Tunggu_Dies\",\"std_menit_default\":null,\"std_menit_progressive\":null,\"deleted_at\":null,\"created_at\":\"2026-05-23T13:49:06.000000Z\",\"updated_at\":\"2026-05-23T13:49:06.000000Z\"}}','[]','2026-05-23 06:49:06','2026-05-23 06:49:06'),(5,'master_losstime','created','App\\Models\\MasterLosstime',5,'created',NULL,NULL,'{\"attributes\":{\"id\":5,\"kode\":\"5\",\"jenis_losstime\":\"MP_Tidak_Ada\",\"std_menit_default\":null,\"std_menit_progressive\":null,\"deleted_at\":null,\"created_at\":\"2026-05-23T13:49:06.000000Z\",\"updated_at\":\"2026-05-23T13:49:06.000000Z\"}}','[]','2026-05-23 06:49:06','2026-05-23 06:49:06'),(6,'master_losstime','created','App\\Models\\MasterLosstime',6,'created',NULL,NULL,'{\"attributes\":{\"id\":6,\"kode\":\"7\",\"jenis_losstime\":\"Trial_QC\",\"std_menit_default\":5,\"std_menit_progressive\":5,\"deleted_at\":null,\"created_at\":\"2026-05-23T13:49:06.000000Z\",\"updated_at\":\"2026-05-23T13:49:06.000000Z\"}}','[]','2026-05-23 06:49:06','2026-05-23 06:49:06'),(7,'master_losstime','created','App\\Models\\MasterLosstime',7,'created',NULL,NULL,'{\"attributes\":{\"id\":7,\"kode\":\"9\",\"jenis_losstime\":\"Setting_Dies\",\"std_menit_default\":10,\"std_menit_progressive\":15,\"deleted_at\":null,\"created_at\":\"2026-05-23T13:49:06.000000Z\",\"updated_at\":\"2026-05-23T13:49:06.000000Z\"}}','[]','2026-05-23 06:49:06','2026-05-23 06:49:06'),(8,'master_losstime','deleted','App\\Models\\MasterLosstime',1,'deleted','App\\Models\\User',1,'{\"old\":{\"id\":1,\"kode\":\"1\",\"jenis_losstime\":\"Mesin_Rusak\",\"std_menit_default\":null,\"std_menit_progressive\":null,\"deleted_at\":\"2026-05-23T14:36:35.000000Z\",\"created_at\":\"2026-05-23T13:49:06.000000Z\",\"updated_at\":\"2026-05-23T14:36:35.000000Z\"}}','[]','2026-05-23 07:36:35','2026-05-23 07:36:35'),(9,'master_losstime','deleted','App\\Models\\MasterLosstime',2,'deleted','App\\Models\\User',1,'{\"old\":{\"id\":2,\"kode\":\"2\",\"jenis_losstime\":\"Tunggu_Crane\",\"std_menit_default\":null,\"std_menit_progressive\":null,\"deleted_at\":\"2026-05-23T14:36:38.000000Z\",\"created_at\":\"2026-05-23T13:49:06.000000Z\",\"updated_at\":\"2026-05-23T14:36:38.000000Z\"}}','[]','2026-05-23 07:36:38','2026-05-23 07:36:38'),(10,'master_losstime','deleted','App\\Models\\MasterLosstime',3,'deleted','App\\Models\\User',1,'{\"old\":{\"id\":3,\"kode\":\"3\",\"jenis_losstime\":\"Tunggu_Material\",\"std_menit_default\":null,\"std_menit_progressive\":null,\"deleted_at\":\"2026-05-23T14:36:41.000000Z\",\"created_at\":\"2026-05-23T13:49:06.000000Z\",\"updated_at\":\"2026-05-23T14:36:41.000000Z\"}}','[]','2026-05-23 07:36:41','2026-05-23 07:36:41'),(11,'master_losstime','deleted','App\\Models\\MasterLosstime',4,'deleted','App\\Models\\User',1,'{\"old\":{\"id\":4,\"kode\":\"4\",\"jenis_losstime\":\"Tunggu_Dies\",\"std_menit_default\":null,\"std_menit_progressive\":null,\"deleted_at\":\"2026-05-23T14:36:43.000000Z\",\"created_at\":\"2026-05-23T13:49:06.000000Z\",\"updated_at\":\"2026-05-23T14:36:43.000000Z\"}}','[]','2026-05-23 07:36:43','2026-05-23 07:36:43'),(12,'master_losstime','deleted','App\\Models\\MasterLosstime',5,'deleted','App\\Models\\User',1,'{\"old\":{\"id\":5,\"kode\":\"5\",\"jenis_losstime\":\"MP_Tidak_Ada\",\"std_menit_default\":null,\"std_menit_progressive\":null,\"deleted_at\":\"2026-05-23T14:36:45.000000Z\",\"created_at\":\"2026-05-23T13:49:06.000000Z\",\"updated_at\":\"2026-05-23T14:36:45.000000Z\"}}','[]','2026-05-23 07:36:45','2026-05-23 07:36:45'),(13,'master_losstime','deleted','App\\Models\\MasterLosstime',6,'deleted','App\\Models\\User',1,'{\"old\":{\"id\":6,\"kode\":\"7\",\"jenis_losstime\":\"Trial_QC\",\"std_menit_default\":5,\"std_menit_progressive\":5,\"deleted_at\":\"2026-05-23T14:36:48.000000Z\",\"created_at\":\"2026-05-23T13:49:06.000000Z\",\"updated_at\":\"2026-05-23T14:36:48.000000Z\"}}','[]','2026-05-23 07:36:48','2026-05-23 07:36:48'),(14,'master_losstime','deleted','App\\Models\\MasterLosstime',7,'deleted','App\\Models\\User',1,'{\"old\":{\"id\":7,\"kode\":\"9\",\"jenis_losstime\":\"Setting_Dies\",\"std_menit_default\":10,\"std_menit_progressive\":15,\"deleted_at\":\"2026-05-23T14:36:50.000000Z\",\"created_at\":\"2026-05-23T13:49:06.000000Z\",\"updated_at\":\"2026-05-23T14:36:50.000000Z\"}}','[]','2026-05-23 07:36:50','2026-05-23 07:36:50'),(15,'master_mesin','created','App\\Models\\MasterMesin',1,'created','App\\Models\\User',1,'{\"attributes\":{\"id\":1,\"id_mesin\":\"P1\",\"nama_mesin\":\"FUKUI 300T\",\"line\":\"P\",\"tipe_mesin\":\"PROGRESSIVE\",\"kapasitas_max_stroke\":2800,\"deleted_at\":null,\"created_at\":\"2026-05-23T14:51:42.000000Z\",\"updated_at\":\"2026-05-23T14:51:42.000000Z\"}}','[]','2026-05-23 07:51:42','2026-05-23 07:51:42'),(16,'master_part','created','App\\Models\\MasterPart',1,'created','App\\Models\\User',1,'{\"attributes\":{\"id\":1,\"part_no\":\"BT-091A-3\",\"customer\":\"PT. DPM\",\"part_name\":\"BRACKET 003 BT\",\"deleted_at\":null,\"created_at\":\"2026-05-23T14:52:25.000000Z\",\"updated_at\":\"2026-05-23T14:52:25.000000Z\"}}','[]','2026-05-23 07:52:25','2026-05-23 07:52:25'),(17,'master_part_proses','created','App\\Models\\MasterPartProses',1,'created','App\\Models\\User',1,'{\"attributes\":{\"id\":1,\"id_part\":1,\"id_proses\":\"1\",\"nama_proses\":\"PROGRESSIVE\",\"sph_standar_produksi\":\"900.00\",\"cycle_time_standar_produksi\":\"5.00\",\"sph_standar_qc\":\"400.00\",\"cycle_time_standar_qc\":\"4.00\",\"std_dandori_menit\":15,\"deleted_at\":null,\"created_at\":\"2026-05-23T14:53:09.000000Z\",\"updated_at\":\"2026-05-23T14:53:09.000000Z\"}}','[]','2026-05-23 07:53:09','2026-05-23 07:53:09'),(18,'master_karyawan','created','App\\Models\\MasterKaryawan',1,'created','App\\Models\\User',1,'{\"attributes\":{\"id\":1,\"nik\":\"051\",\"nama_karyawan\":\"DANAR\",\"departemen\":\"Produksi\",\"deleted_at\":null,\"created_at\":\"2026-05-23T14:53:44.000000Z\",\"updated_at\":\"2026-05-23T14:53:44.000000Z\"}}','[]','2026-05-23 07:53:44','2026-05-23 07:53:44'),(19,'transaksi_produksi','created','App\\Models\\TransaksiProduksi',1,'created','App\\Models\\User',1,'{\"attributes\":{\"id\":1,\"tanggal_produksi\":\"2026-05-23\",\"id_shift\":1,\"id_karyawan\":1,\"id_mesin\":1,\"id_part_proses\":1,\"jam_start\":\"12:00:00\",\"jam_finish\":\"13:00:00\",\"qty_aktual\":700,\"actual_cycle_time\":\"5.14\",\"created_by\":1,\"updated_by\":null,\"deleted_at\":null,\"created_at\":\"2026-05-23T15:01:44.000000Z\",\"updated_at\":\"2026-05-23T15:01:44.000000Z\"}}','[]','2026-05-23 08:01:44','2026-05-23 08:01:44'),(20,'master_losstime','updated','App\\Models\\MasterLosstime',1,'updated','App\\Models\\User',1,'{\"attributes\":{\"id\":1,\"kode\":\"1\",\"jenis_losstime\":\"Mesin Rusak\",\"std_menit_default\":0,\"std_menit_progressive\":0,\"deleted_at\":\"2026-05-23T14:36:35.000000Z\",\"created_at\":\"2026-05-23T13:49:06.000000Z\",\"updated_at\":\"2026-05-23T20:10:07.000000Z\"},\"old\":{\"id\":1,\"kode\":\"1\",\"jenis_losstime\":\"Mesin_Rusak\",\"std_menit_default\":null,\"std_menit_progressive\":null,\"deleted_at\":\"2026-05-23T14:36:35.000000Z\",\"created_at\":\"2026-05-23T13:49:06.000000Z\",\"updated_at\":\"2026-05-23T14:36:35.000000Z\"}}','[]','2026-05-23 13:10:07','2026-05-23 13:10:07'),(21,'master_losstime','updated','App\\Models\\MasterLosstime',2,'updated','App\\Models\\User',1,'{\"attributes\":{\"id\":2,\"kode\":\"2\",\"jenis_losstime\":\"Tunggu Crane\",\"std_menit_default\":0,\"std_menit_progressive\":0,\"deleted_at\":\"2026-05-23T14:36:38.000000Z\",\"created_at\":\"2026-05-23T13:49:06.000000Z\",\"updated_at\":\"2026-05-23T20:10:07.000000Z\"},\"old\":{\"id\":2,\"kode\":\"2\",\"jenis_losstime\":\"Tunggu_Crane\",\"std_menit_default\":null,\"std_menit_progressive\":null,\"deleted_at\":\"2026-05-23T14:36:38.000000Z\",\"created_at\":\"2026-05-23T13:49:06.000000Z\",\"updated_at\":\"2026-05-23T14:36:38.000000Z\"}}','[]','2026-05-23 13:10:07','2026-05-23 13:10:07'),(22,'master_losstime','updated','App\\Models\\MasterLosstime',3,'updated','App\\Models\\User',1,'{\"attributes\":{\"id\":3,\"kode\":\"3\",\"jenis_losstime\":\"Tunggu Material\",\"std_menit_default\":0,\"std_menit_progressive\":0,\"deleted_at\":\"2026-05-23T14:36:41.000000Z\",\"created_at\":\"2026-05-23T13:49:06.000000Z\",\"updated_at\":\"2026-05-23T20:10:07.000000Z\"},\"old\":{\"id\":3,\"kode\":\"3\",\"jenis_losstime\":\"Tunggu_Material\",\"std_menit_default\":null,\"std_menit_progressive\":null,\"deleted_at\":\"2026-05-23T14:36:41.000000Z\",\"created_at\":\"2026-05-23T13:49:06.000000Z\",\"updated_at\":\"2026-05-23T14:36:41.000000Z\"}}','[]','2026-05-23 13:10:07','2026-05-23 13:10:07'),(23,'master_losstime','updated','App\\Models\\MasterLosstime',4,'updated','App\\Models\\User',1,'{\"attributes\":{\"id\":4,\"kode\":\"4\",\"jenis_losstime\":\"Tunggu Dies\",\"std_menit_default\":0,\"std_menit_progressive\":0,\"deleted_at\":\"2026-05-23T14:36:43.000000Z\",\"created_at\":\"2026-05-23T13:49:06.000000Z\",\"updated_at\":\"2026-05-23T20:10:07.000000Z\"},\"old\":{\"id\":4,\"kode\":\"4\",\"jenis_losstime\":\"Tunggu_Dies\",\"std_menit_default\":null,\"std_menit_progressive\":null,\"deleted_at\":\"2026-05-23T14:36:43.000000Z\",\"created_at\":\"2026-05-23T13:49:06.000000Z\",\"updated_at\":\"2026-05-23T14:36:43.000000Z\"}}','[]','2026-05-23 13:10:07','2026-05-23 13:10:07'),(24,'master_losstime','updated','App\\Models\\MasterLosstime',5,'updated','App\\Models\\User',1,'{\"attributes\":{\"id\":5,\"kode\":\"5\",\"jenis_losstime\":\"MP Tidak Ada\",\"std_menit_default\":0,\"std_menit_progressive\":0,\"deleted_at\":\"2026-05-23T14:36:45.000000Z\",\"created_at\":\"2026-05-23T13:49:06.000000Z\",\"updated_at\":\"2026-05-23T20:10:07.000000Z\"},\"old\":{\"id\":5,\"kode\":\"5\",\"jenis_losstime\":\"MP_Tidak_Ada\",\"std_menit_default\":null,\"std_menit_progressive\":null,\"deleted_at\":\"2026-05-23T14:36:45.000000Z\",\"created_at\":\"2026-05-23T13:49:06.000000Z\",\"updated_at\":\"2026-05-23T14:36:45.000000Z\"}}','[]','2026-05-23 13:10:07','2026-05-23 13:10:07'),(25,'master_losstime','created','App\\Models\\MasterLosstime',8,'created','App\\Models\\User',1,'{\"attributes\":{\"id\":8,\"kode\":\"6\",\"jenis_losstime\":\"Repair Dies\",\"std_menit_default\":0,\"std_menit_progressive\":0,\"deleted_at\":null,\"created_at\":\"2026-05-23T20:10:07.000000Z\",\"updated_at\":\"2026-05-23T20:10:07.000000Z\"}}','[]','2026-05-23 13:10:07','2026-05-23 13:10:07'),(26,'master_losstime','updated','App\\Models\\MasterLosstime',6,'updated','App\\Models\\User',1,'{\"attributes\":{\"id\":6,\"kode\":\"7\",\"jenis_losstime\":\"Trial QC\",\"std_menit_default\":5,\"std_menit_progressive\":5,\"deleted_at\":\"2026-05-23T14:36:48.000000Z\",\"created_at\":\"2026-05-23T13:49:06.000000Z\",\"updated_at\":\"2026-05-23T20:10:07.000000Z\"},\"old\":{\"id\":6,\"kode\":\"7\",\"jenis_losstime\":\"Trial_QC\",\"std_menit_default\":5,\"std_menit_progressive\":5,\"deleted_at\":\"2026-05-23T14:36:48.000000Z\",\"created_at\":\"2026-05-23T13:49:06.000000Z\",\"updated_at\":\"2026-05-23T14:36:48.000000Z\"}}','[]','2026-05-23 13:10:07','2026-05-23 13:10:07'),(27,'master_losstime','created','App\\Models\\MasterLosstime',9,'created','App\\Models\\User',1,'{\"attributes\":{\"id\":9,\"kode\":\"8\",\"jenis_losstime\":\"Listrik Padam\",\"std_menit_default\":0,\"std_menit_progressive\":0,\"deleted_at\":null,\"created_at\":\"2026-05-23T20:10:07.000000Z\",\"updated_at\":\"2026-05-23T20:10:07.000000Z\"}}','[]','2026-05-23 13:10:07','2026-05-23 13:10:07'),(28,'master_losstime','updated','App\\Models\\MasterLosstime',7,'updated','App\\Models\\User',1,'{\"attributes\":{\"id\":7,\"kode\":\"9\",\"jenis_losstime\":\"Setting Dies\",\"std_menit_default\":10,\"std_menit_progressive\":15,\"deleted_at\":\"2026-05-23T14:36:50.000000Z\",\"created_at\":\"2026-05-23T13:49:06.000000Z\",\"updated_at\":\"2026-05-23T20:10:07.000000Z\"},\"old\":{\"id\":7,\"kode\":\"9\",\"jenis_losstime\":\"Setting_Dies\",\"std_menit_default\":10,\"std_menit_progressive\":15,\"deleted_at\":\"2026-05-23T14:36:50.000000Z\",\"created_at\":\"2026-05-23T13:49:06.000000Z\",\"updated_at\":\"2026-05-23T14:36:50.000000Z\"}}','[]','2026-05-23 13:10:07','2026-05-23 13:10:07'),(29,'master_losstime','created','App\\Models\\MasterLosstime',10,'created','App\\Models\\User',1,'{\"attributes\":{\"id\":10,\"kode\":\"10\",\"jenis_losstime\":\"Tidak Ada Material\",\"std_menit_default\":0,\"std_menit_progressive\":0,\"deleted_at\":null,\"created_at\":\"2026-05-23T20:10:07.000000Z\",\"updated_at\":\"2026-05-23T20:10:07.000000Z\"}}','[]','2026-05-23 13:10:07','2026-05-23 13:10:07'),(30,'master_losstime','created','App\\Models\\MasterLosstime',11,'created','App\\Models\\User',1,'{\"attributes\":{\"id\":11,\"kode\":\"11\",\"jenis_losstime\":\"Setting Mesin\",\"std_menit_default\":0,\"std_menit_progressive\":0,\"deleted_at\":null,\"created_at\":\"2026-05-23T20:10:07.000000Z\",\"updated_at\":\"2026-05-23T20:10:07.000000Z\"}}','[]','2026-05-23 13:10:07','2026-05-23 13:10:07'),(31,'master_losstime','created','App\\Models\\MasterLosstime',12,'created','App\\Models\\User',1,'{\"attributes\":{\"id\":12,\"kode\":\"12\",\"jenis_losstime\":\"Repair Jig\",\"std_menit_default\":0,\"std_menit_progressive\":0,\"deleted_at\":null,\"created_at\":\"2026-05-23T20:10:07.000000Z\",\"updated_at\":\"2026-05-23T20:10:07.000000Z\"}}','[]','2026-05-23 13:10:07','2026-05-23 13:10:07'),(32,'master_losstime','created','App\\Models\\MasterLosstime',13,'created','App\\Models\\User',1,'{\"attributes\":{\"id\":13,\"kode\":\"13\",\"jenis_losstime\":\"Bersih Bersih\",\"std_menit_default\":0,\"std_menit_progressive\":0,\"deleted_at\":null,\"created_at\":\"2026-05-23T20:10:07.000000Z\",\"updated_at\":\"2026-05-23T20:10:07.000000Z\"}}','[]','2026-05-23 13:10:07','2026-05-23 13:10:07'),(33,'master_losstime','created','App\\Models\\MasterLosstime',14,'created','App\\Models\\User',1,'{\"attributes\":{\"id\":14,\"kode\":\"14\",\"jenis_losstime\":\"Tunggu WIP\",\"std_menit_default\":0,\"std_menit_progressive\":0,\"deleted_at\":null,\"created_at\":\"2026-05-23T20:10:07.000000Z\",\"updated_at\":\"2026-05-23T20:10:07.000000Z\"}}','[]','2026-05-23 13:10:07','2026-05-23 13:10:07'),(34,'master_losstime','created','App\\Models\\MasterLosstime',15,'created','App\\Models\\User',1,'{\"attributes\":{\"id\":15,\"kode\":\"15\",\"jenis_losstime\":\"Ke Toilet\",\"std_menit_default\":0,\"std_menit_progressive\":0,\"deleted_at\":null,\"created_at\":\"2026-05-23T20:10:07.000000Z\",\"updated_at\":\"2026-05-23T20:10:07.000000Z\"}}','[]','2026-05-23 13:10:07','2026-05-23 13:10:07'),(35,'master_losstime','created','App\\Models\\MasterLosstime',16,'created','App\\Models\\User',1,'{\"attributes\":{\"id\":16,\"kode\":\"16\",\"jenis_losstime\":\"Tunggu Box\",\"std_menit_default\":0,\"std_menit_progressive\":0,\"deleted_at\":null,\"created_at\":\"2026-05-23T20:10:07.000000Z\",\"updated_at\":\"2026-05-23T20:10:07.000000Z\"}}','[]','2026-05-23 13:10:07','2026-05-23 13:10:07'),(36,'master_losstime','created','App\\Models\\MasterLosstime',17,'created','App\\Models\\User',1,'{\"attributes\":{\"id\":17,\"kode\":\"17\",\"jenis_losstime\":\"Tidak Ada Planning\",\"std_menit_default\":0,\"std_menit_progressive\":0,\"deleted_at\":null,\"created_at\":\"2026-05-23T20:10:07.000000Z\",\"updated_at\":\"2026-05-23T20:10:07.000000Z\"}}','[]','2026-05-23 13:10:07','2026-05-23 13:10:07'),(37,'master_losstime','created','App\\Models\\MasterLosstime',18,'created','App\\Models\\User',1,'{\"attributes\":{\"id\":18,\"kode\":\"18\",\"jenis_losstime\":\"Tunggu Keputusan\",\"std_menit_default\":0,\"std_menit_progressive\":0,\"deleted_at\":null,\"created_at\":\"2026-05-23T20:10:07.000000Z\",\"updated_at\":\"2026-05-23T20:10:07.000000Z\"}}','[]','2026-05-23 13:10:07','2026-05-23 13:10:07'),(38,'master_mesin','created','App\\Models\\MasterMesin',2,'created','App\\Models\\User',1,'{\"attributes\":{\"id\":2,\"id_mesin\":\"P2\",\"nama_mesin\":\"HIKARI 100T\",\"line\":\"P\",\"tipe_mesin\":\"PROGRESSIVE\",\"kapasitas_max_stroke\":2800,\"deleted_at\":null,\"created_at\":\"2026-05-23T20:19:57.000000Z\",\"updated_at\":\"2026-05-23T20:19:57.000000Z\"}}','[]','2026-05-23 13:19:57','2026-05-23 13:19:57'),(39,'master_mesin','created','App\\Models\\MasterMesin',3,'created','App\\Models\\User',1,'{\"attributes\":{\"id\":3,\"id_mesin\":\"P3\",\"nama_mesin\":\"AMADA 150T\",\"line\":\"P\",\"tipe_mesin\":\"STAMPING\",\"kapasitas_max_stroke\":2800,\"deleted_at\":null,\"created_at\":\"2026-05-23T20:19:57.000000Z\",\"updated_at\":\"2026-05-23T20:19:57.000000Z\"}}','[]','2026-05-23 13:19:57','2026-05-23 13:19:57'),(40,'master_mesin','created','App\\Models\\MasterMesin',4,'created','App\\Models\\User',1,'{\"attributes\":{\"id\":4,\"id_mesin\":\"A1\",\"nama_mesin\":\"AIDA 150T\",\"line\":\"A\",\"tipe_mesin\":\"STAMPING\",\"kapasitas_max_stroke\":2800,\"deleted_at\":null,\"created_at\":\"2026-05-23T20:19:57.000000Z\",\"updated_at\":\"2026-05-23T20:19:57.000000Z\"}}','[]','2026-05-23 13:19:57','2026-05-23 13:19:57'),(41,'master_mesin','created','App\\Models\\MasterMesin',5,'created','App\\Models\\User',1,'{\"attributes\":{\"id\":5,\"id_mesin\":\"A2\",\"nama_mesin\":\"SEYI 160T\",\"line\":\"A\",\"tipe_mesin\":\"STAMPING\",\"kapasitas_max_stroke\":2800,\"deleted_at\":null,\"created_at\":\"2026-05-23T20:19:57.000000Z\",\"updated_at\":\"2026-05-23T20:19:57.000000Z\"}}','[]','2026-05-23 13:19:57','2026-05-23 13:19:57'),(42,'master_mesin','created','App\\Models\\MasterMesin',6,'created','App\\Models\\User',1,'{\"attributes\":{\"id\":6,\"id_mesin\":\"A3\",\"nama_mesin\":\"SHINAGAWA 150T\",\"line\":\"A\",\"tipe_mesin\":\"STAMPING\",\"kapasitas_max_stroke\":2800,\"deleted_at\":null,\"created_at\":\"2026-05-23T20:19:57.000000Z\",\"updated_at\":\"2026-05-23T20:19:57.000000Z\"}}','[]','2026-05-23 13:19:57','2026-05-23 13:19:57'),(43,'master_mesin','created','App\\Models\\MasterMesin',7,'created','App\\Models\\User',1,'{\"attributes\":{\"id\":7,\"id_mesin\":\"A4\",\"nama_mesin\":\"AMADA 80T\",\"line\":\"A\",\"tipe_mesin\":\"STAMPING\",\"kapasitas_max_stroke\":2800,\"deleted_at\":null,\"created_at\":\"2026-05-23T20:19:57.000000Z\",\"updated_at\":\"2026-05-23T20:19:57.000000Z\"}}','[]','2026-05-23 13:19:57','2026-05-23 13:19:57'),(44,'master_mesin','created','App\\Models\\MasterMesin',8,'created','App\\Models\\User',1,'{\"attributes\":{\"id\":8,\"id_mesin\":\"B1\",\"nama_mesin\":\"AMADA 200T\",\"line\":\"B\",\"tipe_mesin\":\"STAMPING\",\"kapasitas_max_stroke\":2800,\"deleted_at\":null,\"created_at\":\"2026-05-23T20:19:57.000000Z\",\"updated_at\":\"2026-05-23T20:19:57.000000Z\"}}','[]','2026-05-23 13:19:57','2026-05-23 13:19:57'),(45,'master_mesin','created','App\\Models\\MasterMesin',9,'created','App\\Models\\User',1,'{\"attributes\":{\"id\":9,\"id_mesin\":\"B2\",\"nama_mesin\":\"YUSHIDA 250T\",\"line\":\"B\",\"tipe_mesin\":\"STAMPING\",\"kapasitas_max_stroke\":2800,\"deleted_at\":null,\"created_at\":\"2026-05-23T20:19:57.000000Z\",\"updated_at\":\"2026-05-23T20:19:57.000000Z\"}}','[]','2026-05-23 13:19:57','2026-05-23 13:19:57'),(46,'master_mesin','created','App\\Models\\MasterMesin',10,'created','App\\Models\\User',1,'{\"attributes\":{\"id\":10,\"id_mesin\":\"B3\",\"nama_mesin\":\"SEYI 250T\",\"line\":\"B\",\"tipe_mesin\":\"STAMPING\",\"kapasitas_max_stroke\":2800,\"deleted_at\":null,\"created_at\":\"2026-05-23T20:19:57.000000Z\",\"updated_at\":\"2026-05-23T20:19:57.000000Z\"}}','[]','2026-05-23 13:19:57','2026-05-23 13:19:57'),(47,'master_mesin','created','App\\Models\\MasterMesin',11,'created','App\\Models\\User',1,'{\"attributes\":{\"id\":11,\"id_mesin\":\"B4\",\"nama_mesin\":\"SHINOHARA 200T\",\"line\":\"B\",\"tipe_mesin\":\"STAMPING\",\"kapasitas_max_stroke\":2800,\"deleted_at\":null,\"created_at\":\"2026-05-23T20:19:57.000000Z\",\"updated_at\":\"2026-05-23T20:19:57.000000Z\"}}','[]','2026-05-23 13:19:57','2026-05-23 13:19:57'),(48,'master_mesin','created','App\\Models\\MasterMesin',12,'created','App\\Models\\User',1,'{\"attributes\":{\"id\":12,\"id_mesin\":\"B5\",\"nama_mesin\":\"SHIMOMURA 200T\",\"line\":\"B\",\"tipe_mesin\":\"STAMPING\",\"kapasitas_max_stroke\":2800,\"deleted_at\":null,\"created_at\":\"2026-05-23T20:19:57.000000Z\",\"updated_at\":\"2026-05-23T20:19:57.000000Z\"}}','[]','2026-05-23 13:19:57','2026-05-23 13:19:57'),(49,'master_mesin','created','App\\Models\\MasterMesin',13,'created','App\\Models\\User',1,'{\"attributes\":{\"id\":13,\"id_mesin\":\"C1\",\"nama_mesin\":\"OKK 100T\",\"line\":\"C\",\"tipe_mesin\":\"STAMPING\",\"kapasitas_max_stroke\":2800,\"deleted_at\":null,\"created_at\":\"2026-05-23T20:19:57.000000Z\",\"updated_at\":\"2026-05-23T20:19:57.000000Z\"}}','[]','2026-05-23 13:19:57','2026-05-23 13:19:57'),(50,'master_mesin','created','App\\Models\\MasterMesin',14,'created','App\\Models\\User',1,'{\"attributes\":{\"id\":14,\"id_mesin\":\"C2\",\"nama_mesin\":\"KOMATSU 80T\",\"line\":\"C\",\"tipe_mesin\":\"STAMPING\",\"kapasitas_max_stroke\":2800,\"deleted_at\":null,\"created_at\":\"2026-05-23T20:19:57.000000Z\",\"updated_at\":\"2026-05-23T20:19:57.000000Z\"}}','[]','2026-05-23 13:19:57','2026-05-23 13:19:57'),(51,'master_mesin','created','App\\Models\\MasterMesin',15,'created','App\\Models\\User',1,'{\"attributes\":{\"id\":15,\"id_mesin\":\"C3\",\"nama_mesin\":\"AIDA 60T\",\"line\":\"C\",\"tipe_mesin\":\"STAMPING\",\"kapasitas_max_stroke\":2800,\"deleted_at\":null,\"created_at\":\"2026-05-23T20:19:57.000000Z\",\"updated_at\":\"2026-05-23T20:19:57.000000Z\"}}','[]','2026-05-23 13:19:57','2026-05-23 13:19:57'),(52,'master_mesin','created','App\\Models\\MasterMesin',16,'created','App\\Models\\User',1,'{\"attributes\":{\"id\":16,\"id_mesin\":\"C4\",\"nama_mesin\":\"NAGAO 100T\",\"line\":\"C\",\"tipe_mesin\":\"STAMPING\",\"kapasitas_max_stroke\":2800,\"deleted_at\":null,\"created_at\":\"2026-05-23T20:19:57.000000Z\",\"updated_at\":\"2026-05-23T20:19:57.000000Z\"}}','[]','2026-05-23 13:19:57','2026-05-23 13:19:57'),(53,'master_mesin','created','App\\Models\\MasterMesin',17,'created','App\\Models\\User',1,'{\"attributes\":{\"id\":17,\"id_mesin\":\"C5\",\"nama_mesin\":\"NAGAO 100T\",\"line\":\"C\",\"tipe_mesin\":\"STAMPING\",\"kapasitas_max_stroke\":2800,\"deleted_at\":null,\"created_at\":\"2026-05-23T20:19:57.000000Z\",\"updated_at\":\"2026-05-23T20:19:57.000000Z\"}}','[]','2026-05-23 13:19:57','2026-05-23 13:19:57'),(54,'master_mesin','created','App\\Models\\MasterMesin',18,'created','App\\Models\\User',1,'{\"attributes\":{\"id\":18,\"id_mesin\":\"C6\",\"nama_mesin\":\"CHINFONG 60T\",\"line\":\"C\",\"tipe_mesin\":\"STAMPING\",\"kapasitas_max_stroke\":2800,\"deleted_at\":null,\"created_at\":\"2026-05-23T20:19:57.000000Z\",\"updated_at\":\"2026-05-23T20:19:57.000000Z\"}}','[]','2026-05-23 13:19:57','2026-05-23 13:19:57'),(55,'master_mesin','created','App\\Models\\MasterMesin',19,'created','App\\Models\\User',1,'{\"attributes\":{\"id\":19,\"id_mesin\":\"C7\",\"nama_mesin\":\"KOMATSU 60T\",\"line\":\"C\",\"tipe_mesin\":\"STAMPING\",\"kapasitas_max_stroke\":2800,\"deleted_at\":null,\"created_at\":\"2026-05-23T20:19:57.000000Z\",\"updated_at\":\"2026-05-23T20:19:57.000000Z\"}}','[]','2026-05-23 13:19:57','2026-05-23 13:19:57'),(56,'master_mesin','created','App\\Models\\MasterMesin',20,'created','App\\Models\\User',1,'{\"attributes\":{\"id\":20,\"id_mesin\":\"C8\",\"nama_mesin\":\"FENGYU 60T\",\"line\":\"C\",\"tipe_mesin\":\"STAMPING\",\"kapasitas_max_stroke\":2800,\"deleted_at\":null,\"created_at\":\"2026-05-23T20:19:57.000000Z\",\"updated_at\":\"2026-05-23T20:19:57.000000Z\"}}','[]','2026-05-23 13:19:57','2026-05-23 13:19:57'),(57,'master_mesin','created','App\\Models\\MasterMesin',21,'created','App\\Models\\User',1,'{\"attributes\":{\"id\":21,\"id_mesin\":\"C9\",\"nama_mesin\":\"FENGYU 45T\",\"line\":\"C\",\"tipe_mesin\":\"STAMPING\",\"kapasitas_max_stroke\":2800,\"deleted_at\":null,\"created_at\":\"2026-05-23T20:19:57.000000Z\",\"updated_at\":\"2026-05-23T20:19:57.000000Z\"}}','[]','2026-05-23 13:19:57','2026-05-23 13:19:57'),(58,'master_mesin','created','App\\Models\\MasterMesin',22,'created','App\\Models\\User',1,'{\"attributes\":{\"id\":22,\"id_mesin\":\"C10\",\"nama_mesin\":\"AIDA 80T\",\"line\":\"C\",\"tipe_mesin\":\"STAMPING\",\"kapasitas_max_stroke\":2800,\"deleted_at\":null,\"created_at\":\"2026-05-23T20:19:57.000000Z\",\"updated_at\":\"2026-05-23T20:19:57.000000Z\"}}','[]','2026-05-23 13:19:57','2026-05-23 13:19:57'),(59,'master_mesin','created','App\\Models\\MasterMesin',23,'created','App\\Models\\User',1,'{\"attributes\":{\"id\":23,\"id_mesin\":\"C11\",\"nama_mesin\":\"WASHINO 75T\",\"line\":\"C\",\"tipe_mesin\":\"STAMPING\",\"kapasitas_max_stroke\":2800,\"deleted_at\":null,\"created_at\":\"2026-05-23T20:19:57.000000Z\",\"updated_at\":\"2026-05-23T20:19:57.000000Z\"}}','[]','2026-05-23 13:19:57','2026-05-23 13:19:57'),(60,'master_mesin','created','App\\Models\\MasterMesin',24,'created','App\\Models\\User',1,'{\"attributes\":{\"id\":24,\"id_mesin\":\"C12\",\"nama_mesin\":\"SEYI 60T\",\"line\":\"C\",\"tipe_mesin\":\"STAMPING\",\"kapasitas_max_stroke\":2800,\"deleted_at\":null,\"created_at\":\"2026-05-23T20:19:57.000000Z\",\"updated_at\":\"2026-05-23T20:19:57.000000Z\"}}','[]','2026-05-23 13:19:57','2026-05-23 13:19:57');
/*!40000 ALTER TABLE `activity_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cache`
--

DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cache` (
  `key` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` bigint(20) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cache`
--

LOCK TABLES `cache` WRITE;
/*!40000 ALTER TABLE `cache` DISABLE KEYS */;
INSERT INTO `cache` VALUES ('optima-cache-admin@local.optima|127.0.0.1','i:1;',1780460428),('optima-cache-admin@local.optima|127.0.0.1:timer','i:1780460428;',1780460428),('optima-cache-manager@optima.local|127.0.0.1','i:3;',1779636015),('optima-cache-manager@optima.local|127.0.0.1:timer','i:1779636015;',1779636015),('optima-cache-managerproduksi@optima.local|127.0.0.1','i:1;',1779569094),('optima-cache-managerproduksi@optima.local|127.0.0.1:timer','i:1779569094;',1779569094);
/*!40000 ALTER TABLE `cache` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cache_locks`
--

DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` bigint(20) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cache_locks`
--

LOCK TABLES `cache_locks` WRITE;
/*!40000 ALTER TABLE `cache_locks` DISABLE KEYS */;
/*!40000 ALTER TABLE `cache_locks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `failed_jobs`
--

DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `failed_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) NOT NULL,
  `connection` varchar(255) NOT NULL,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`),
  KEY `failed_jobs_connection_queue_failed_at_index` (`connection`,`queue`,`failed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `failed_jobs`
--

LOCK TABLES `failed_jobs` WRITE;
/*!40000 ALTER TABLE `failed_jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `failed_jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `job_batches`
--

DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `job_batches` (
  `id` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `total_jobs` int(11) NOT NULL,
  `pending_jobs` int(11) NOT NULL,
  `failed_jobs` int(11) NOT NULL,
  `failed_job_ids` longtext NOT NULL,
  `options` mediumtext DEFAULT NULL,
  `cancelled_at` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `finished_at` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `job_batches`
--

LOCK TABLES `job_batches` WRITE;
/*!40000 ALTER TABLE `job_batches` DISABLE KEYS */;
/*!40000 ALTER TABLE `job_batches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `jobs`
--

DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` smallint(5) unsigned NOT NULL,
  `reserved_at` int(10) unsigned DEFAULT NULL,
  `available_at` int(10) unsigned NOT NULL,
  `created_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `jobs`
--

LOCK TABLES `jobs` WRITE;
/*!40000 ALTER TABLE `jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `master_jenis_ng`
--

DROP TABLE IF EXISTS `master_jenis_ng`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `master_jenis_ng` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `kode` varchar(255) NOT NULL,
  `nama_ng` varchar(255) NOT NULL,
  `deskripsi` text DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `master_jenis_ng_kode_unique` (`kode`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `master_jenis_ng`
--

LOCK TABLES `master_jenis_ng` WRITE;
/*!40000 ALTER TABLE `master_jenis_ng` DISABLE KEYS */;
INSERT INTO `master_jenis_ng` VALUES (1,'1','KARAT','-',NULL,'2026-05-24 03:48:42','2026-05-24 03:48:42'),(2,'2','SCRATCH','-',NULL,'2026-05-24 03:48:42','2026-05-24 03:48:42'),(3,'3','EXSCRAP','-',NULL,'2026-05-24 03:48:42','2026-05-24 03:48:42'),(4,'4','BURRY','-',NULL,'2026-05-24 03:48:42','2026-05-24 03:48:42'),(5,'5','HOLE OF CENTER','-',NULL,'2026-05-24 03:48:42','2026-05-24 03:48:42'),(6,'6','DIMENSI ABNORMAL','-',NULL,'2026-05-24 03:48:42','2026-05-24 03:48:42'),(7,'7','LAS JEBOL','-',NULL,'2026-05-24 03:48:42','2026-05-24 03:48:42'),(8,'8','LAS KEROPOS','-',NULL,'2026-05-24 03:48:42','2026-05-24 03:48:42'),(9,'9','ULIR RUSAK','-',NULL,'2026-05-24 03:48:42','2026-05-24 03:48:42'),(10,'10','MATERIAL MINUS','-',NULL,'2026-05-24 03:48:42','2026-05-24 03:48:42');
/*!40000 ALTER TABLE `master_jenis_ng` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `master_karyawan`
--

DROP TABLE IF EXISTS `master_karyawan`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `master_karyawan` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `nik` varchar(255) NOT NULL,
  `nama_karyawan` varchar(255) NOT NULL,
  `departemen` enum('Produksi','QC') NOT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `master_karyawan_nik_unique` (`nik`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `master_karyawan`
--

LOCK TABLES `master_karyawan` WRITE;
/*!40000 ALTER TABLE `master_karyawan` DISABLE KEYS */;
INSERT INTO `master_karyawan` VALUES (1,'051','Danar','Produksi',NULL,'2026-05-23 07:53:44','2026-05-24 08:32:02'),(2,'052','Dhika','Produksi',NULL,'2026-05-24 08:31:54','2026-05-24 08:32:11'),(3,'053','Sandi','Produksi',NULL,'2026-05-24 08:34:56','2026-05-24 08:34:56');
/*!40000 ALTER TABLE `master_karyawan` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `master_losstime`
--

DROP TABLE IF EXISTS `master_losstime`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `master_losstime` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `kode` varchar(255) NOT NULL,
  `jenis_losstime` varchar(255) NOT NULL,
  `std_menit_default` int(11) DEFAULT NULL,
  `std_menit_progressive` int(11) DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `master_losstime_kode_unique` (`kode`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `master_losstime`
--

LOCK TABLES `master_losstime` WRITE;
/*!40000 ALTER TABLE `master_losstime` DISABLE KEYS */;
INSERT INTO `master_losstime` VALUES (1,'1','Mesin Rusak',0,0,NULL,'2026-05-23 06:49:06','2026-05-23 13:10:07'),(2,'2','Tunggu Crane',0,0,NULL,'2026-05-23 06:49:06','2026-05-23 13:10:07'),(3,'3','Tunggu Material',0,0,NULL,'2026-05-23 06:49:06','2026-05-23 13:10:07'),(4,'4','Tunggu Dies',0,0,NULL,'2026-05-23 06:49:06','2026-05-23 13:10:07'),(5,'5','MP Tidak Ada',0,0,NULL,'2026-05-23 06:49:06','2026-05-23 13:10:07'),(6,'7','Trial QC',5,5,NULL,'2026-05-23 06:49:06','2026-05-23 13:10:07'),(7,'9','Setting Dies',10,15,NULL,'2026-05-23 06:49:06','2026-05-23 13:10:07'),(8,'6','Repair Dies',0,0,NULL,'2026-05-23 13:10:07','2026-05-23 13:10:07'),(9,'8','Listrik Padam',0,0,NULL,'2026-05-23 13:10:07','2026-05-23 13:10:07'),(10,'10','Tidak Ada Material',0,0,NULL,'2026-05-23 13:10:07','2026-05-23 13:10:07'),(11,'11','Setting Mesin',0,0,NULL,'2026-05-23 13:10:07','2026-05-23 13:10:07'),(12,'12','Repair Jig',0,0,NULL,'2026-05-23 13:10:07','2026-05-23 13:10:07'),(13,'13','Bersih Bersih',0,0,NULL,'2026-05-23 13:10:07','2026-05-23 13:10:07'),(14,'14','Tunggu WIP',0,0,NULL,'2026-05-23 13:10:07','2026-05-23 13:10:07'),(15,'15','Ke Toilet',0,0,NULL,'2026-05-23 13:10:07','2026-05-23 13:10:07'),(16,'16','Tunggu Box',0,0,NULL,'2026-05-23 13:10:07','2026-05-23 13:10:07'),(17,'17','Tidak Ada Planning',0,0,NULL,'2026-05-23 13:10:07','2026-05-23 13:10:07'),(18,'18','Tunggu Keputusan',0,0,NULL,'2026-05-23 13:10:07','2026-05-23 13:10:07');
/*!40000 ALTER TABLE `master_losstime` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `master_mesin`
--

DROP TABLE IF EXISTS `master_mesin`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `master_mesin` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_mesin` varchar(255) NOT NULL,
  `nama_mesin` varchar(255) NOT NULL,
  `line` varchar(255) NOT NULL,
  `tipe_mesin` enum('PROGRESSIVE','STAMPING') NOT NULL,
  `kapasitas_max_stroke` int(11) DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `master_mesin_id_mesin_unique` (`id_mesin`)
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `master_mesin`
--

LOCK TABLES `master_mesin` WRITE;
/*!40000 ALTER TABLE `master_mesin` DISABLE KEYS */;
INSERT INTO `master_mesin` VALUES (1,'P1','FUKUI 300T','P','PROGRESSIVE',2800,NULL,'2026-05-23 07:51:42','2026-05-23 07:51:42'),(2,'P2','HIKARI 100T','P','PROGRESSIVE',2800,NULL,'2026-05-23 13:19:57','2026-05-23 13:19:57'),(3,'P3','AMADA 150T','P','STAMPING',2800,NULL,'2026-05-23 13:19:57','2026-05-23 13:19:57'),(4,'A1','AIDA 150T','A','STAMPING',2800,NULL,'2026-05-23 13:19:57','2026-05-23 13:19:57'),(5,'A2','SEYI 160T','A','STAMPING',2800,NULL,'2026-05-23 13:19:57','2026-05-23 13:19:57'),(6,'A3','SHINAGAWA 150T','A','STAMPING',2800,NULL,'2026-05-23 13:19:57','2026-05-23 13:19:57'),(7,'A4','AMADA 80T','A','STAMPING',2800,NULL,'2026-05-23 13:19:57','2026-05-23 13:19:57'),(8,'B1','AMADA 200T','B','STAMPING',2800,NULL,'2026-05-23 13:19:57','2026-05-23 13:19:57'),(9,'B2','YUSHIDA 250T','B','STAMPING',2800,NULL,'2026-05-23 13:19:57','2026-05-23 13:19:57'),(10,'B3','SEYI 250T','B','STAMPING',2800,NULL,'2026-05-23 13:19:57','2026-05-23 13:19:57'),(11,'B4','SHINOHARA 200T','B','STAMPING',2800,NULL,'2026-05-23 13:19:57','2026-05-23 13:19:57'),(12,'B5','SHIMOMURA 200T','B','STAMPING',2800,NULL,'2026-05-23 13:19:57','2026-05-23 13:19:57'),(13,'C1','OKK 100T','C','STAMPING',2800,NULL,'2026-05-23 13:19:57','2026-05-23 13:19:57'),(14,'C2','KOMATSU 80T','C','STAMPING',2800,NULL,'2026-05-23 13:19:57','2026-05-23 13:19:57'),(15,'C3','AIDA 60T','C','STAMPING',2800,NULL,'2026-05-23 13:19:57','2026-05-23 13:19:57'),(16,'C4','NAGAO 100T','C','STAMPING',2800,NULL,'2026-05-23 13:19:57','2026-05-23 13:19:57'),(17,'C5','NAGAO 100T','C','STAMPING',2800,NULL,'2026-05-23 13:19:57','2026-05-23 13:19:57'),(18,'C6','CHINFONG 60T','C','STAMPING',2800,NULL,'2026-05-23 13:19:57','2026-05-23 13:19:57'),(19,'C7','KOMATSU 60T','C','STAMPING',2800,NULL,'2026-05-23 13:19:57','2026-05-23 13:19:57'),(20,'C8','FENGYU 60T','C','STAMPING',2800,NULL,'2026-05-23 13:19:57','2026-05-23 13:19:57'),(21,'C9','FENGYU 45T','C','STAMPING',2800,NULL,'2026-05-23 13:19:57','2026-05-23 13:19:57'),(22,'C10','AIDA 80T','C','STAMPING',2800,NULL,'2026-05-23 13:19:57','2026-05-23 13:19:57'),(23,'C11','WASHINO 75T','C','STAMPING',2800,NULL,'2026-05-23 13:19:57','2026-05-23 13:19:57'),(24,'C12','SEYI 60T','C','STAMPING',2800,NULL,'2026-05-23 13:19:57','2026-05-23 13:19:57');
/*!40000 ALTER TABLE `master_mesin` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `master_part`
--

DROP TABLE IF EXISTS `master_part`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `master_part` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `part_no` varchar(255) NOT NULL,
  `customer` varchar(100) DEFAULT NULL,
  `part_name` varchar(255) NOT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `master_part_part_no_unique` (`part_no`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `master_part`
--

LOCK TABLES `master_part` WRITE;
/*!40000 ALTER TABLE `master_part` DISABLE KEYS */;
INSERT INTO `master_part` VALUES (1,'BT-091A-3','PT. DPM','BRACKET 003 BT',NULL,'2026-05-23 07:52:25','2026-05-26 02:19:12'),(2,'123','PT. DPM','BRACKET A',NULL,'2026-06-02 21:38:36','2026-06-02 21:38:58');
/*!40000 ALTER TABLE `master_part` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `master_part_proses`
--

DROP TABLE IF EXISTS `master_part_proses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `master_part_proses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_part` bigint(20) unsigned NOT NULL,
  `id_proses` varchar(255) NOT NULL,
  `nama_proses` varchar(255) NOT NULL,
  `sph_standar_produksi` decimal(8,2) NOT NULL DEFAULT 0.00,
  `sph_last_upgraded_at` timestamp NULL DEFAULT NULL COMMENT 'NULL = belum pernah diupgrade; diisi saat admin terapkan rekomendasi',
  `sph_standar_qc` decimal(8,2) NOT NULL DEFAULT 0.00,
  `sph_qc_last_upgraded_at` timestamp NULL DEFAULT NULL COMMENT 'NULL = belum pernah diupgrade; diisi otomatis saat admin terapkan rekomendasi QC',
  `std_trial_qc_menit` int(11) NOT NULL DEFAULT 0,
  `std_setting_dies_menit` int(11) NOT NULL DEFAULT 15,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `master_part_proses_id_part_id_proses_unique` (`id_part`,`id_proses`),
  CONSTRAINT `master_part_proses_id_part_foreign` FOREIGN KEY (`id_part`) REFERENCES `master_part` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `master_part_proses`
--

LOCK TABLES `master_part_proses` WRITE;
/*!40000 ALTER TABLE `master_part_proses` DISABLE KEYS */;
INSERT INTO `master_part_proses` VALUES (1,1,'1','PROGRESSIVE',1000.00,NULL,400.00,NULL,0,15,NULL,'2026-05-23 07:53:09','2026-05-26 02:18:08'),(3,1,'2','BENDING',400.00,NULL,400.00,NULL,5,10,NULL,'2026-06-02 21:51:49','2026-06-02 21:51:49'),(4,2,'1','PROGRESSIVE',900.00,NULL,490.00,NULL,5,15,NULL,'2026-06-02 21:54:01','2026-06-02 21:54:01');
/*!40000 ALTER TABLE `master_part_proses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `master_part_proses_sph_log`
--

DROP TABLE IF EXISTS `master_part_proses_sph_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `master_part_proses_sph_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_part_proses` bigint(20) unsigned NOT NULL,
  `type` enum('produksi','qc') NOT NULL DEFAULT 'produksi',
  `sph_standar_lama` int(11) NOT NULL,
  `sph_standar_baru` int(11) NOT NULL,
  `upgraded_by` bigint(20) unsigned DEFAULT NULL,
  `catatan` varchar(255) DEFAULT NULL,
  `upgraded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `master_part_proses_sph_log_upgraded_by_foreign` (`upgraded_by`),
  KEY `master_part_proses_sph_log_id_part_proses_index` (`id_part_proses`),
  CONSTRAINT `master_part_proses_sph_log_id_part_proses_foreign` FOREIGN KEY (`id_part_proses`) REFERENCES `master_part_proses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `master_part_proses_sph_log_upgraded_by_foreign` FOREIGN KEY (`upgraded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `master_part_proses_sph_log`
--

LOCK TABLES `master_part_proses_sph_log` WRITE;
/*!40000 ALTER TABLE `master_part_proses_sph_log` DISABLE KEYS */;
/*!40000 ALTER TABLE `master_part_proses_sph_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `master_shift`
--

DROP TABLE IF EXISTS `master_shift`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `master_shift` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `nama_shift` varchar(255) NOT NULL,
  `jam_start` time NOT NULL,
  `jam_finish` time NOT NULL,
  `jam_start_jumat` time DEFAULT NULL,
  `jam_finish_jumat` time DEFAULT NULL,
  `jam_start_sabtu` time DEFAULT NULL,
  `jam_finish_sabtu` time DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `master_shift`
--

LOCK TABLES `master_shift` WRITE;
/*!40000 ALTER TABLE `master_shift` DISABLE KEYS */;
INSERT INTO `master_shift` VALUES (1,'Shift 1','07:00:00','15:00:00','07:00:00','15:30:00','07:00:00','12:15:00','2026-05-23 06:49:06','2026-05-25 02:42:48',NULL),(2,'Shift 2','13:00:00','21:00:00','13:00:00','21:00:00','13:00:00','18:15:00','2026-05-23 06:49:06','2026-05-25 02:51:40',NULL),(3,'Shift 3','22:00:00','06:00:00',NULL,NULL,NULL,NULL,'2026-05-23 06:49:06','2026-05-23 13:24:46','2026-05-23 13:24:46');
/*!40000 ALTER TABLE `master_shift` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `master_shift_istirahat`
--

DROP TABLE IF EXISTS `master_shift_istirahat`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `master_shift_istirahat` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `id_shift` bigint(20) unsigned NOT NULL,
  `kelompok_hari` enum('senin_kamis','jumat','sabtu') NOT NULL,
  `jam_start` time NOT NULL,
  `jam_finish` time NOT NULL,
  PRIMARY KEY (`id`),
  KEY `master_shift_istirahat_id_shift_foreign` (`id_shift`),
  CONSTRAINT `master_shift_istirahat_id_shift_foreign` FOREIGN KEY (`id_shift`) REFERENCES `master_shift` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `master_shift_istirahat`
--

LOCK TABLES `master_shift_istirahat` WRITE;
/*!40000 ALTER TABLE `master_shift_istirahat` DISABLE KEYS */;
INSERT INTO `master_shift_istirahat` VALUES (3,1,'senin_kamis','10:00:00','10:15:00'),(4,1,'senin_kamis','12:00:00','12:45:00'),(5,1,'jumat','10:00:00','10:15:00'),(6,1,'jumat','11:45:00','13:00:00'),(7,1,'sabtu','10:00:00','10:15:00'),(17,2,'senin_kamis','15:00:00','15:15:00'),(18,2,'senin_kamis','18:15:00','19:00:00'),(19,2,'jumat','15:00:00','15:15:00'),(20,2,'jumat','18:15:00','19:00:00'),(21,2,'sabtu','15:00:00','15:15:00');
/*!40000 ALTER TABLE `master_shift_istirahat` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `migrations`
--

DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=26 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `migrations`
--

LOCK TABLES `migrations` WRITE;
/*!40000 ALTER TABLE `migrations` DISABLE KEYS */;
INSERT INTO `migrations` VALUES (1,'0001_01_01_000000_create_users_table',1),(2,'0001_01_01_000001_create_cache_table',1),(3,'0001_01_01_000002_create_jobs_table',1),(4,'2026_05_23_060626_create_activity_log_table',1),(5,'2026_05_23_061139_create_master_shift_table',1),(6,'2026_05_23_061141_create_master_karyawan_table',1),(7,'2026_05_23_061144_create_master_mesin_table',1),(8,'2026_05_23_061144_create_master_part_table',1),(9,'2026_05_23_061145_create_master_losstime_table',1),(10,'2026_05_23_061146_create_master_part_proses_table',1),(11,'2026_05_23_061149_add_role_to_users_table',1),(12,'2026_05_23_061149_create_transaksi_produksi_table',1),(13,'2026_05_23_061149_create_transaksi_qc_table',1),(14,'2026_05_23_061150_create_transaksi_dandori_table',1),(15,'2026_05_23_061152_create_transaksi_dandori_rincian_table',1),(16,'2026_05_23_083949_add_soft_deletes_to_master_shift_table',1),(17,'2026_05_23_135439_add_date_indexes_to_transaksi_tables',2),(18,'2026_05_23_215000_add_customer_to_master_part_table',3),(19,'2026_05_24_000001_create_master_jenis_ng_table',4),(20,'2026_05_24_000002_add_ng_fields_to_transaksi_tables',4),(21,'2026_05_24_100000_restructure_transaksi_dandori_rincian',5),(22,'2026_05_24_200000_upgrade_master_part_proses_columns',6),(23,'2026_05_24_150350_add_sph_upgrade_tracking_to_master_part_proses',7),(24,'2026_05_24_150934_add_sph_qc_upgrade_tracking',8),(25,'2026_05_25_093832_add_day_schedules_and_istirahat_to_shift',9);
/*!40000 ALTER TABLE `migrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `password_reset_tokens`
--

DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `password_reset_tokens`
--

LOCK TABLES `password_reset_tokens` WRITE;
/*!40000 ALTER TABLE `password_reset_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `password_reset_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sessions`
--

DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `payload` longtext NOT NULL,
  `last_activity` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sessions`
--

LOCK TABLES `sessions` WRITE;
/*!40000 ALTER TABLE `sessions` DISABLE KEYS */;
INSERT INTO `sessions` VALUES ('6IfvKhM0Ck22LzFQ04QfGCECzkCf5MaNZsZIMYfc',1,'127.0.0.1','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Safari/605.1.15','eyJfdG9rZW4iOiJRZHNpakQxbDRZOXVRZHhPYmVGdGtFcmhmQk1Iblg5MWxSRTlMMUxOIiwiX3ByZXZpb3VzIjp7InVybCI6Imh0dHA6XC9cLzEyNy4wLjAuMTo4MDAwXC9tYXN0ZXJcL3BhcnRcL2ltcG9ydC10ZW1wbGF0ZSIsInJvdXRlIjoibWFzdGVyLnBhcnQuaW1wb3J0LXRlbXBsYXRlIn0sIl9mbGFzaCI6eyJvbGQiOltdLCJuZXciOltdfSwidXJsIjpbXSwibG9naW5fd2ViXzU5YmEzNmFkZGMyYjJmOTQwMTU4MGYwMTRjN2Y1OGVhNGUzMDk4OWQiOjF9',1779787165),('O2MoaJRYUxsCoEcOvf7n9fRzk93f8cFVzv8qhzVk',1,'127.0.0.1','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Safari/605.1.15','eyJfdG9rZW4iOiJhOU1xTkgwQ1lZRUVvVWdOSzduR0JlUHAzejVQV2tna3NSNk53bWR2IiwiX3ByZXZpb3VzIjp7InVybCI6Imh0dHA6XC9cLzEyNy4wLjAuMTo4MDAwXC9pbnB1dFwvcHJvZHVrc2kiLCJyb3V0ZSI6ImlucHV0LnByb2R1a3NpLmluZGV4In0sIl9mbGFzaCI6eyJvbGQiOltdLCJuZXciOltdfSwidXJsIjpbXSwibG9naW5fd2ViXzU5YmEzNmFkZGMyYjJmOTQwMTU4MGYwMTRjN2Y1OGVhNGUzMDk4OWQiOjF9',1780463562);
/*!40000 ALTER TABLE `sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `transaksi_dandori_rincian`
--

DROP TABLE IF EXISTS `transaksi_dandori_rincian`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `transaksi_dandori_rincian` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tanggal` date NOT NULL,
  `id_shift` bigint(20) unsigned NOT NULL,
  `id_karyawan` bigint(20) unsigned NOT NULL,
  `id_mesin` bigint(20) unsigned NOT NULL,
  `id_part_proses` bigint(20) unsigned NOT NULL,
  `id_losstime` bigint(20) unsigned NOT NULL,
  `actual_menit` decimal(8,2) NOT NULL COMMENT 'Input dari operator',
  `std_menit` decimal(8,2) NOT NULL COMMENT 'Snapshot std dari master saat input',
  `over_menit` decimal(8,2) NOT NULL DEFAULT 0.00 COMMENT 'max(0, actual - std)',
  `created_by` bigint(20) unsigned NOT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `transaksi_dandori_rincian_id_shift_foreign` (`id_shift`),
  KEY `transaksi_dandori_rincian_id_karyawan_foreign` (`id_karyawan`),
  KEY `transaksi_dandori_rincian_id_mesin_foreign` (`id_mesin`),
  KEY `transaksi_dandori_rincian_id_part_proses_foreign` (`id_part_proses`),
  KEY `transaksi_dandori_rincian_id_losstime_foreign` (`id_losstime`),
  KEY `transaksi_dandori_rincian_created_by_foreign` (`created_by`),
  KEY `transaksi_dandori_rincian_tanggal_id_mesin_index` (`tanggal`,`id_mesin`),
  KEY `transaksi_dandori_rincian_tanggal_id_part_proses_index` (`tanggal`,`id_part_proses`),
  KEY `transaksi_dandori_rincian_tanggal_index` (`tanggal`),
  CONSTRAINT `transaksi_dandori_rincian_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `transaksi_dandori_rincian_id_karyawan_foreign` FOREIGN KEY (`id_karyawan`) REFERENCES `master_karyawan` (`id`),
  CONSTRAINT `transaksi_dandori_rincian_id_losstime_foreign` FOREIGN KEY (`id_losstime`) REFERENCES `master_losstime` (`id`),
  CONSTRAINT `transaksi_dandori_rincian_id_mesin_foreign` FOREIGN KEY (`id_mesin`) REFERENCES `master_mesin` (`id`),
  CONSTRAINT `transaksi_dandori_rincian_id_part_proses_foreign` FOREIGN KEY (`id_part_proses`) REFERENCES `master_part_proses` (`id`),
  CONSTRAINT `transaksi_dandori_rincian_id_shift_foreign` FOREIGN KEY (`id_shift`) REFERENCES `master_shift` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `transaksi_dandori_rincian`
--

LOCK TABLES `transaksi_dandori_rincian` WRITE;
/*!40000 ALTER TABLE `transaksi_dandori_rincian` DISABLE KEYS */;
INSERT INTO `transaksi_dandori_rincian` VALUES (1,'2026-05-23',1,1,4,1,1,10.00,0.00,10.00,1,NULL,'2026-05-24 06:40:57','2026-05-24 06:40:57'),(2,'2026-05-23',1,1,4,1,7,20.00,15.00,5.00,1,NULL,'2026-05-24 06:40:57','2026-05-24 06:40:57'),(3,'2026-05-23',1,1,4,1,3,10.00,0.00,10.00,1,NULL,'2026-05-24 06:40:57','2026-05-24 06:40:57'),(4,'2026-05-23',1,1,4,1,6,5.00,5.00,0.00,1,NULL,'2026-05-24 06:40:57','2026-05-24 06:40:57'),(5,'2026-05-24',1,1,6,1,16,10.00,0.00,10.00,1,NULL,'2026-05-24 06:48:47','2026-05-24 06:48:47'),(6,'2026-05-24',1,1,6,1,14,15.00,0.00,15.00,1,NULL,'2026-05-24 06:48:47','2026-05-24 06:48:47'),(7,'2026-05-24',1,1,6,1,7,20.00,15.00,5.00,1,NULL,'2026-05-24 06:48:47','2026-05-24 06:48:47'),(8,'2026-05-24',1,1,6,1,6,10.00,5.00,5.00,1,NULL,'2026-05-24 06:48:47','2026-05-24 06:48:47'),(9,'2026-05-24',1,1,6,1,2,15.00,0.00,15.00,1,NULL,'2026-05-24 06:48:47','2026-05-24 06:48:47');
/*!40000 ALTER TABLE `transaksi_dandori_rincian` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `transaksi_produksi`
--

DROP TABLE IF EXISTS `transaksi_produksi`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `transaksi_produksi` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tanggal_produksi` date NOT NULL,
  `id_shift` bigint(20) unsigned NOT NULL,
  `id_karyawan` bigint(20) unsigned NOT NULL,
  `id_mesin` bigint(20) unsigned NOT NULL,
  `id_part_proses` bigint(20) unsigned NOT NULL,
  `jam_start` time NOT NULL,
  `jam_finish` time NOT NULL,
  `qty_aktual` int(11) NOT NULL,
  `qty_ng` int(10) unsigned DEFAULT NULL,
  `id_jenis_ng` bigint(20) unsigned DEFAULT NULL,
  `actual_cycle_time` decimal(8,2) NOT NULL DEFAULT 0.00,
  `created_by` bigint(20) unsigned NOT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `transaksi_produksi_id_shift_foreign` (`id_shift`),
  KEY `transaksi_produksi_id_karyawan_foreign` (`id_karyawan`),
  KEY `transaksi_produksi_id_mesin_foreign` (`id_mesin`),
  KEY `transaksi_produksi_id_part_proses_foreign` (`id_part_proses`),
  KEY `transaksi_produksi_created_by_foreign` (`created_by`),
  KEY `transaksi_produksi_updated_by_foreign` (`updated_by`),
  KEY `transaksi_produksi_tanggal_produksi_id_karyawan_index` (`tanggal_produksi`,`id_karyawan`),
  KEY `transaksi_produksi_tanggal_produksi_id_mesin_index` (`tanggal_produksi`,`id_mesin`),
  KEY `transaksi_produksi_tanggal_produksi_index` (`tanggal_produksi`),
  KEY `transaksi_produksi_id_jenis_ng_foreign` (`id_jenis_ng`),
  CONSTRAINT `transaksi_produksi_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `transaksi_produksi_id_jenis_ng_foreign` FOREIGN KEY (`id_jenis_ng`) REFERENCES `master_jenis_ng` (`id`) ON DELETE SET NULL,
  CONSTRAINT `transaksi_produksi_id_karyawan_foreign` FOREIGN KEY (`id_karyawan`) REFERENCES `master_karyawan` (`id`),
  CONSTRAINT `transaksi_produksi_id_mesin_foreign` FOREIGN KEY (`id_mesin`) REFERENCES `master_mesin` (`id`),
  CONSTRAINT `transaksi_produksi_id_part_proses_foreign` FOREIGN KEY (`id_part_proses`) REFERENCES `master_part_proses` (`id`),
  CONSTRAINT `transaksi_produksi_id_shift_foreign` FOREIGN KEY (`id_shift`) REFERENCES `master_shift` (`id`),
  CONSTRAINT `transaksi_produksi_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `transaksi_produksi`
--

LOCK TABLES `transaksi_produksi` WRITE;
/*!40000 ALTER TABLE `transaksi_produksi` DISABLE KEYS */;
INSERT INTO `transaksi_produksi` VALUES (1,'2026-05-23',1,1,1,1,'12:00:00','13:00:00',700,NULL,NULL,5.14,1,NULL,'2026-05-26 02:17:55','2026-05-23 08:01:44','2026-05-26 02:17:55'),(2,'2026-05-24',1,1,6,1,'12:00:00','14:00:00',1700,NULL,NULL,4.24,1,NULL,'2026-05-26 02:17:45','2026-05-24 07:07:12','2026-05-26 02:17:45'),(3,'2026-05-24',1,1,7,1,'10:00:00','11:15:00',1100,NULL,NULL,4.09,1,NULL,'2026-05-26 02:17:41','2026-05-24 07:08:21','2026-05-26 02:17:41'),(4,'2026-05-24',1,1,5,1,'14:00:00','15:00:00',1000,NULL,NULL,3.60,1,NULL,'2026-05-26 02:17:38','2026-05-24 07:36:01','2026-05-26 02:17:38'),(5,'2026-05-23',1,2,8,1,'10:00:00','11:45:00',980,NULL,NULL,6.43,1,NULL,'2026-05-26 02:17:52','2026-05-24 08:32:58','2026-05-26 02:17:52'),(6,'2026-05-24',1,2,4,1,'13:00:00','14:30:00',1030,NULL,NULL,5.24,1,NULL,'2026-05-26 02:17:34','2026-05-24 08:33:32','2026-05-26 02:17:34'),(7,'2026-05-23',1,3,6,1,'14:15:00','15:40:00',790,NULL,NULL,6.46,1,NULL,'2026-05-26 02:17:48','2026-05-24 08:35:24','2026-05-26 02:17:48');
/*!40000 ALTER TABLE `transaksi_produksi` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `transaksi_qc`
--

DROP TABLE IF EXISTS `transaksi_qc`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `transaksi_qc` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tgl_pengecekan` date NOT NULL,
  `tgl_produksi_part` date NOT NULL,
  `id_shift` bigint(20) unsigned NOT NULL,
  `id_karyawan_qc` bigint(20) unsigned NOT NULL,
  `id_part_proses` bigint(20) unsigned NOT NULL,
  `jam_start` time NOT NULL,
  `jam_finish` time NOT NULL,
  `qty_ok` int(11) NOT NULL,
  `qty_ng` int(11) NOT NULL DEFAULT 0,
  `id_jenis_ng` bigint(20) unsigned DEFAULT NULL,
  `actual_cycle_time` decimal(8,2) NOT NULL DEFAULT 0.00,
  `created_by` bigint(20) unsigned NOT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `transaksi_qc_id_shift_foreign` (`id_shift`),
  KEY `transaksi_qc_id_karyawan_qc_foreign` (`id_karyawan_qc`),
  KEY `transaksi_qc_id_part_proses_foreign` (`id_part_proses`),
  KEY `transaksi_qc_created_by_foreign` (`created_by`),
  KEY `transaksi_qc_updated_by_foreign` (`updated_by`),
  KEY `transaksi_qc_tgl_pengecekan_id_karyawan_qc_index` (`tgl_pengecekan`,`id_karyawan_qc`),
  KEY `transaksi_qc_tgl_pengecekan_index` (`tgl_pengecekan`),
  KEY `transaksi_qc_id_jenis_ng_foreign` (`id_jenis_ng`),
  CONSTRAINT `transaksi_qc_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `transaksi_qc_id_jenis_ng_foreign` FOREIGN KEY (`id_jenis_ng`) REFERENCES `master_jenis_ng` (`id`) ON DELETE SET NULL,
  CONSTRAINT `transaksi_qc_id_karyawan_qc_foreign` FOREIGN KEY (`id_karyawan_qc`) REFERENCES `master_karyawan` (`id`),
  CONSTRAINT `transaksi_qc_id_part_proses_foreign` FOREIGN KEY (`id_part_proses`) REFERENCES `master_part_proses` (`id`),
  CONSTRAINT `transaksi_qc_id_shift_foreign` FOREIGN KEY (`id_shift`) REFERENCES `master_shift` (`id`),
  CONSTRAINT `transaksi_qc_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `transaksi_qc`
--

LOCK TABLES `transaksi_qc` WRITE;
/*!40000 ALTER TABLE `transaksi_qc` DISABLE KEYS */;
/*!40000 ALTER TABLE `transaksi_qc` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `role` enum('admin','staff_produksi','staff_qc','manager') NOT NULL DEFAULT 'staff_produksi',
  `id_karyawan` bigint(20) unsigned DEFAULT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  KEY `users_id_karyawan_foreign` (`id_karyawan`),
  CONSTRAINT `users_id_karyawan_foreign` FOREIGN KEY (`id_karyawan`) REFERENCES `master_karyawan` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'Administrator','admin@optima.local','admin',NULL,'2026-05-23 06:49:06','$2y$12$0RHJVSQKp.UtJagBse3xUO5BWcMNS4WSTWr9nYl2507OufYzJUvCG',NULL,'2026-05-23 06:49:06','2026-05-23 06:49:06'),(2,'Staff Produksi','staffproduksi@optima.local','staff_produksi',NULL,NULL,'$2y$12$t4Zdp/CvInFQFpmce/haz.rRNf2mVBqQWpNQkczB5PrX80761E0q2',NULL,'2026-05-23 11:51:42','2026-05-23 11:51:42'),(3,'Staff Quality','staffquality@optima.local','staff_qc',NULL,NULL,'$2y$12$PvbqoS5gl70.6qYXrxNX6e1xgyOzOPScnkxkebJUhxt3YWq0BifHK',NULL,'2026-05-23 13:42:16','2026-05-23 13:42:16'),(4,'Manager Produksi','managerprod@optima.local','manager',NULL,NULL,'$2y$12$zQAzlc.kL/GSpB2LUALnhOUw7PsURouRBxt2ynalYHFWJn8Fnkq6K',NULL,'2026-05-23 13:43:06','2026-05-23 13:43:06');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-06-03 12:14:36
