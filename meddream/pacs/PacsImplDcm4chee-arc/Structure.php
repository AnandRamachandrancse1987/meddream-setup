<?php

namespace Softneta\MedDream\Core\Pacs\Dcm4chee_arc;

use Softneta\MedDream\Core\Constants;
use Softneta\MedDream\Core\PathUtils;
use Softneta\MedDream\Core\Pacs\StructureIface;
use Softneta\MedDream\Core\Pacs\StructureAbstract;


/** @brief Implementation of StructureIface for <tt>$pacs='dcm4chee-arc'</tt>. */
class PacsPartStructure extends StructureAbstract implements StructureIface
{
	private function seriesIsVideoQuality($seriesdescription)
	{
		if ($seriesdescription == NULL)
			return false;

		$parts = explode(':', $seriesdescription);
		if (count($parts) != 2)
			return false;
		//first item is not empty and oteh is digit
		if (($parts[0] != '') && is_numeric($parts[1]))
			return $parts;

		return false;
	}


	/**
	 * make exclude list of series uid, which are video qualities
	 * if there is no original series for this quality - it will not be added to
	 * ecxlude list
	 *
	 * @param array $allSeries
	 * @return array
	 */
	private function excludeVideoQualitySeries($allSeries)
	{
		$excludequalityseries = array();
		$originals = array();
		$notoriginals = array();
		foreach ($allSeries as $row1)
		{
			$seriesUID = (string) $row1["pk"];
			$description = $this->shared->cleanDbString((string) $row1["series_desc"]);
			$seriesnuber = (string) $row1['series_no'];

			$quality = $this->seriesIsVideoQuality($this->cs->utf8Encode($description));
			if ($quality === false)
				$originals[$seriesnuber] = $seriesUID;
			else
			{
				if (!isset($notoriginals[$quality[1]]))
					$notoriginals[$quality[1]] = array();

				$notoriginals[$quality[1]][] = $seriesUID;
			}
		}
		//$this->log->asDump('originals: ', $originals);
		//$this->log->asDump('not originals: ', $notoriginals);

		if (count($notoriginals) > 0)
		{
			$seriesindexs = array_keys($notoriginals);
			foreach ($seriesindexs as $seriesnuber)
			{
				if (!isset($originals[$seriesnuber]))
				{
					$originals[$seriesnuber] = $notoriginals[$seriesnuber][0];
					unset($notoriginals[$seriesnuber][0]);
				}
				$excludequalityseries = array_merge($excludequalityseries,
					array_values($notoriginals[$seriesnuber]));
			}

			unset($notoriginals);
		}
		unset($qualitysierieslist);

		return $excludequalityseries;
	}


	/**
	 * collect some data about image, study, and series
	 *
	 * @param string $imageuid
	 * @return array
	 */
	private function getImageStudySeriesData($imageuid)
	{
		$this->log->asDump('begin ' . __METHOD__);

		$authDB = $this->authDB;

		$sql = 'SELECT series.study_fk AS studyuid, instance.inst_no, series.pk, series.series_no,' .
				' series.series_desc, file_ref.instance_fk'.
			' FROM instance, file_ref, series' .
			' WHERE file_ref.pk=' . $authDB->sqlEscapeString($imageuid) .
				' AND file_ref.instance_fk=instance.pk AND instance.series_fk=series.pk ';
		$this->log->asDump('$sql = ', $sql);

		$rs = $authDB->query($sql);
		if (!$rs)
		{
			$this->log->asErr("query failed: '" . $authDB->getError() . "'");
			return array('error' => '[Structure] Database error (1), see logs');
		}

		$row = $authDB->fetchAssoc($rs);
		$authDB->free($rs);

		$return = array();
		if ($row)
		{
			$this->log->asDump('result: ', $row);

			$return['studyuid'] = (string) $row['studyuid'];
			$return['instance'] = (string) $row['inst_no'];
			$return['seriesuid'] = (string) $row['pk'];
			$return['seriesnumb'] = (string) $row['series_no'];
			$return['seriesdescription'] = $this->shared->cleanDbString((string) $row['series_desc']);
			$return['error'] = '';
		}
		else
		{
			$err = "Image not found: '$imageuid'";
			$this->log->asErr($err);
			$return['error'] = $err;
			return $return;
		}

		if (isset($return['studyuid']))
			if ($return['studyuid'] == '')
				$return['error'] = "Study not found for image '$imageuid'";

		$this->log->asDump('$return = ', $return);
		$this->log->asDump('end ' . __METHOD__);

		return $return;
	}


	private function mapRowKeys($row, $inputKey, $outputKey)
	{
		$return = array('error' => '', $outputKey => null);

		if (isset($row[$inputKey]))
			$return[$outputKey] = $row[$inputKey];

		return $return;
	}


	public function instanceGetMetadata($instanceUid, $includePatient = false)
	{
		if (strlen($this->commonData['pacs_gateway_addr']))
		{
			return $this->gw->instanceGetMetadata($instanceUid, $includePatient);
		}

		$log = $this->log;
		$authDB = $this->authDB;

		$log->asDump('begin ' . __METHOD__ . '(', $instanceUid, ', ', $includePatient, ')');

		if ($includePatient)
			$sql = 'SELECT fr.filepath, fr.file_tsuid, fs.fs_uri, i.sop_cuid, p.pat_name AS fullname' .
				' FROM file_ref fr' .
				' LEFT JOIN filesystem fs ON fs.pk = fr.filesystem_fk' .
				' LEFT JOIN instance i ON i.pk = fr.instance_fk' .
				' LEFT JOIN series se ON se.pk = i.series_fk' .
				' LEFT JOIN study st ON st.pk = se.study_fk' .
				' LEFT JOIN patient p ON p.pk = st.patient_fk' .
				' WHERE fr.pk=' . $authDB->sqlEscapeString($instanceUid);
		else
			$sql = 'SELECT filepath, file_tsuid, fs_uri, sop_cuid' .
				' FROM file_ref' .
				' LEFT JOIN filesystem ON filesystem.pk = file_ref.filesystem_fk' .
				' LEFT JOIN instance ON instance.pk = file_ref.instance_fk' .
				' WHERE file_ref.pk=' . $authDB->sqlEscapeString($instanceUid);
		$log->asDump('$sql = ', $sql);

		$rs = $authDB->query($sql);
		if (!$rs)
		{
			$log->asErr("query failed: '" . $authDB->getError() . "'");
			return array('error' => "[Structure] Database error (2), see logs");
		}

		$return = array();
		if ($row = $authDB->fetchAssoc($rs))
		{
			$return['error'] = '';
			$return['uid'] = $instanceUid;

			$log->asDump('result: ', $row);

			$path = PathUtils::stripUriPrefix((string) $row["fs_uri"]);
			$path .= DIRECTORY_SEPARATOR . $row["filepath"];
			$return['path'] = $this->fp->toLocal(urldecode($path));		/* urldecode: embedded spaces etc */
			$return['xfersyntax'] = (string) $row['file_tsuid'];
			$return['bitsstored'] = '8';
			$return['sopclass'] = $row['sop_cuid'];
			if ($includePatient)
			{
				$return['patientid'] = '';
				$return['fullname'] = $this->cs->utf8Encode(trim(str_replace('^', ' ',
					$this->shared->cleanDbString($row['fullname']))));
				$return['firstname'] = '';
				$return['lastname'] = '';
			}
		}
		else
			$return['error'] = "record not found for instance '$instanceUid'";
		$authDB->free($rs);

		$log->asDump('$return = ', $return);
		$log->asDump('end ' . __METHOD__);
		return $return;
	}


	public function instanceGetStudy($instanceUid)
	{
		if (strlen($this->commonData['pacs_gateway_addr']))
		{
			return $this->gw->instanceGetStudy($instanceUid);
		}

		$log = $this->log;
		$authDB = $this->authDB;
		$dbms = $authDB->getDbms();

		$log->asDump('begin ' . __METHOD__ . '(', $instanceUid, ')');

		$limit_pre = '';
		$limit_suf = '';
		if (($dbms == 'MSSQL') || ($dbms == 'SQLSRV'))
			$limit_pre = ' TOP(1)';
		else
			if ($dbms != "OCI8")
				$limit_suf = " LIMIT 1";
		$sql = "SELECT$limit_pre series.study_fk as studyuid" .
			' FROM series' .
			' LEFT JOIN instance ON instance.series_fk=series.pk' .
			' LEFT JOIN file_ref ON file_ref.instance_fk=instance.pk' .
			" WHERE file_ref.pk=" . $authDB->sqlEscapeString($instanceUid) . $limit_suf;
		if ($dbms == 'OCI8')
			$sql = "SELECT * FROM ($sql) WHERE ROWNUM <= 1";
		$this->log->asDump('$sql = ', $sql);

		$rs = $authDB->query($sql);
		if (!$rs)
		{
			$log->asErr("query failed: '" . $authDB->getError() . "'");
			return array('error' => '[Structure] Database error (3), see logs');
		}

		$return = array('error' => '');
		$row = $authDB->fetchAssoc($rs);
		$authDB->free($rs);
		if ($row)
			$return['studyuid'] = $row['studyuid'];
		else
		{
			$log->asWarn(__METHOD__ . ': study not found for instance ' . var_export($instanceUid, true));
			$return['studyuid'] = null;
		}

		$log->asDump('$return = ', $return);
		$log->asDump('end ' . __METHOD__);
		return $return;
	}


	public function instanceUidToKey($instanceUid)
	{
		if (strlen($this->commonData['pacs_gateway_addr']))
		{
			return $this->gw->instanceUidToKey($instanceUid);
		}

		$log = $this->log;
		$authDB = $this->authDB;
		$dbms = $authDB->getDbms();

		$log->asDump('begin ' . __METHOD__ . '(', $instanceUid, ')');

		$u = trim($instanceUid);
		if ($u == '')
		{
			$return = array('error' => '', 'imagepk' => $u);
			$log->asDump('returning: ', $return);
			return $return;
		}

		$limit_pre = '';
		$limit_suf = '';
		if (($dbms == 'MSSQL') || ($dbms == 'SQLSRV'))
			$limit_pre = ' TOP(1)';
		else
			if ($dbms != "OCI8")
				$limit_suf = ' LIMIT 1';

		$sql = "SELECT$limit_pre file_ref.pk" .
			' FROM file_ref' .
			' LEFT JOIN instance ON instance.pk=file_ref.instance_fk' .
			" WHERE instance.sop_iuid='" . $authDB->sqlEscapeString($instanceUid) . "'$limit_suf";
		if ($dbms == 'OCI8')
			$sql = "SELECT * FROM ($sql) WHERE ROWNUM <= 1";
		$log->asDump('$sql = ', $sql);

		$rs = $authDB->query($sql);
		if (!$rs)
		{
			$log->asErr("query failed: '" . $authDB->getError() . "'");
			return array('error' => '[Structure] Database error (4), see logs');
		}

		$row = $authDB->fetchAssoc($rs);
		$authDB->free($rs);
		$this->log->asDump('result: ', $row);

		$return = $this->mapRowKeys($row, 'pk', 'imagepk');
		$this->log->asDump('$return = ', $return);

		$log->asDump('end ' . __METHOD__);
		return $return;
	}


	public function instanceKeyToUid($instanceKey)
	{
		if (strlen($this->commonData['pacs_gateway_addr']))
		{
			return $this->gw->instanceKeyToUid($instanceKey);
		}

		$log = $this->log;
		$authDB = $this->authDB;
		$dbms = $this->commonData['dbms'];

		$log->asDump('begin ' . __METHOD__ . '(', $instanceKey, ')');

		$u = trim($instanceKey);
		if ($u == '')
		{
			$return = array('error' => '', 'imageuid' => $u);
			$log->asDump('returning: ', $return);
			return $return;
		}

		$limit_pre = '';
		$limit_suf = '';
		if (($dbms == 'MSSQL') || ($dbms == 'SQLSRV'))
			$limit_pre = ' TOP(1)';
		else
			if ($dbms != "OCI8")
				$limit_suf = ' LIMIT 1';
		$sql = "SELECT$limit_pre instance.sop_iuid" .
			' FROM instance' .
			' LEFT JOIN file_ref ON file_ref.instance_fk=instance.pk ' .
			' WHERE file_ref.pk=' . $authDB->sqlEscapeString($u) . $limit_suf;
		if ($dbms == 'OCI8')
			$sql = "SELECT * FROM ($sql) WHERE ROWNUM <= 1";
		$log->asDump('$sql = ', $sql);

		$rs = $authDB->query($sql);
		if (!$rs)
		{
			$log->asErr("query failed: '" . $authDB->getError() . "'");
			return array('error' => '[Structure] Database error (5), see logs');
		}

		$row = $authDB->fetchAssoc($rs);
		$authDB->free($rs);
		$this->log->asDump('result: ', $row);

		$return = $this->mapRowKeys($row, 'sop_iuid', 'imageuid');
		$this->log->asDump('$return = ', $return);

		$log->asDump('end ' . __METHOD__);
		return $return;
	}


	public function seriesGetMetadata($seriesUid)
	{
		if (strlen($this->commonData['pacs_gateway_addr']))
		{
			return $this->gw->seriesGetMetadata($seriesUid);
		}

		$log = $this->log;
		$authDB = $this->authDB;
		$dbms = $this->commonData['dbms'];

		$log->asDump('begin ' . __METHOD__ . '(', $seriesUid, ')');

		if (($dbms == 'MSSQL') || ($dbms == 'SQLSRV') || ($dbms == 'POSTGRESQL'))
			$inttype = 'INT';
		else
			$inttype = 'UNSIGNED';
		if ($dbms == "OCI8")
			$orderInstNo = "LPAD(instance.inst_no, 255)";
		else
			$orderInstNo = "CAST(instance.inst_no AS $inttype)";	/* MySQL, MSSQL, etc */
		$sql = 'SELECT filepath, file_tsuid, fs_uri, pat_name' .
			' FROM file_ref' .
			' LEFT JOIN filesystem ON filesystem.pk = file_ref.filesystem_fk' .
			' LEFT JOIN instance ON instance.pk = file_ref.instance_fk' .
			' LEFT JOIN series ON series.pk = instance.series_fk' .
			' LEFT JOIN study ON study.pk = series.study_fk' .
			' LEFT JOIN patient ON patient.pk = study.patient_fk' .
			' WHERE instance.replaced=0 AND instance.series_fk=' . $authDB->sqlEscapeString($seriesUid) .
			" ORDER BY $orderInstNo, instance.content_date, instance.content_time, file_ref.pk DESC";
		$log->asDump('$sql = ', $sql);

		$rs = $authDB->query($sql);
		if (!$rs)
		{
			$log->asErr("query failed: '" . $authDB->getError() . "'");
			return array('error' => '[Structure] Database error (6), see logs');
		}

		$fullName = '';
		$series = array('error' => '');
		$i = 0;
		while ($row = $authDB->fetchAssoc($rs))
		{
			$log->asDump("result #$i: ", $row);

			$fullName = $this->cs->utf8Encode(trim(str_replace('^', ' ',
				$this->shared->cleanDbString($row['pat_name']))));

			$path = PathUtils::stripUriPrefix((string) $row["fs_uri"]);
			$path .= DIRECTORY_SEPARATOR . $row["filepath"];
			$path = $this->fp->toLocal(urldecode($path));	/* urldecode: embedded spaces etc */

			$img = array();
			$img["path"] = $path;
			$img["xfersyntax"] = (string) $row["file_tsuid"];
			$img["bitsstored"] = 8;
			$series["image-".sprintf("%06d", $i++)] = $img;
		}
		$series['count'] = $i;
		$series['firstname'] = '';
		$series['lastname'] = '';
		$series['fullname'] = $fullName;

		$authDB->free($rs);

		$log->asDump('returning: ', $series);
		$log->asDump('end ' . __METHOD__);
		return $series;
	}


	public function seriesUidToKey($seriesUid)
	{
		if (strlen($this->commonData['pacs_gateway_addr']))
		{
			return $this->gw->seriesUidToKey($seriesUid);
		}

		$log = $this->log;
		$authDB = $this->authDB;
		$dbms = $authDB->getDbms();

		$log->asDump('begin ' . __METHOD__ . '(', $seriesUid, ')');

		$u = trim($seriesUid);
		if ($u == '')
		{
			$return = array('error' => '', 'seriespk' => $u);
			$log->asDump('$return = ', $return);
			return $return;
		}

		$limit_pre = '';
		$limit_suf = '';
		if (($dbms == 'MSSQL') || ($dbms == 'SQLSRV'))
			$limit_pre = ' TOP(1)';
		else
			if ($dbms != "OCI8")
				$limit_suf = ' LIMIT 1';
		$sql = "SELECT$limit_pre pk FROM series" .
			" WHERE series_iuid='" . $authDB->sqlEscapeString($u) . "'$limit_suf";
		if ($dbms == 'OCI8')
			$sql = "SELECT * FROM ($sql) WHERE ROWNUM <= 1";
		$log->asDump('$sql = ', $sql);

		$rs = $authDB->query($sql);
		if (!$rs)
		{
			$log->asErr("query failed: '" . $authDB->getError() . "'");
			return array('error' => '[Structure] Database error (7), see logs');
		}

		$row = $authDB->fetchAssoc($rs);
		$authDB->free($rs);
		$this->log->asDump('result: ', $row);

		$return = $this->mapRowKeys($row, 'pk', 'seriespk');
		$this->log->asDump('$return = ', $return);

		$log->asDump('end ' . __METHOD__);
		return $return;
	}


	public function studyGetMetadata($studyUid, $disableFilter = false, $fromCache = false)
	{
		if (strlen($this->commonData['pacs_gateway_addr']))
		{
			return $this->gw->studyGetMetadata($studyUid, $disableFilter, $fromCache);
		}

		$dbms = $this->commonData['dbms'];
		$return = array();
		$return['count'] = 0;
		$return['error'] = 'not authenticated';

		$this->log->asDump('begin ' . __METHOD__ . '(', $studyUid, ', ', $disableFilter, ', ', $fromCache, ')');

		$authDB = $this->authDB;
		if (!$authDB->isAuthenticated())
		{
			$this->log->asErr('not authenticated');
			return $return;
		}

		$cs = $this->cs;

		$return['error'] = '';

		$patientid = '';
		$sourceae = '';
		$studydate = '';
		$studytime = '';
		$lastname = '';
		$firstname = '';

		$sql = 'SELECT patient_fk,study_date,study_time FROM study WHERE pk=' . $authDB->sqlEscapeString($studyUid);
		$this->log->asDump('$sql = ', $sql);

		$rs = $authDB->query($sql);
		if (!$rs)
		{
			$return['error'] = '[Structure] Database error (8), see logs';
			$this->log->asErr("query failed: '" . $authDB->getError() . "'");
			return $return;
		}
		if ($row = $authDB->fetchAssoc($rs))
		{
			$this->log->asDump('result: ', $row);

			$patientid = (string) $row["patient_fk"];
			$studydate = $this->shared->cleanDbString((string) $row["study_date"]);
			$studytime = $this->shared->cleanDbString((string) $row["study_time"]);
		}
		else
		{
			$this->log->asErr('no such study');
			$return['error'] = 'No such study';
			return $return;
		}
		$authDB->free($rs);
		$patient_inst = $patientid;

		$sql = "SELECT pat_name,pat_id FROM patient WHERE pk=$patientid";
		$this->log->asDump('$sql = ', $sql);

		$rs = $authDB->query($sql);
		if (!$rs)
		{
			$return['error'] = '[Structure] Database error (9), see logs';
			$this->log->asErr("query failed: '" . $authDB->getError() . "'");
			return $return;
		}
		if ($row = $authDB->fetchAssoc($rs))
		{
			$this->log->asDump('result: ', $row);

			$patient_inst = $this->shared->cleanDbString($row['pat_id']);
			$lastname = "";
			$firstname = $cs->utf8Encode(trim(str_replace("^", " ",
				$this->shared->cleanDbString((string) $row["pat_name"]))));
		}
		$authDB->free($rs);

		$return['lastname'] = $cs->utf8Encode($lastname);
		$return['firstname'] = $cs->utf8Encode($firstname);
		$return['uid'] = $studyUid;
		$return['patientid'] = $cs->utf8Encode($patient_inst);
		$return['sourceae'] = '';		/* simply reserve position in the array, for a more organized look */
		$return['studydate'] = $studydate;
		$return['studytime'] = $studytime;

		$notes = $this->studyHasReport($studyUid);
		$return['notes'] = $notes['notes'];

		if (($dbms == 'MSSQL') || ($dbms == 'SQLSRV') || ($dbms == 'POSTGRESQL'))
			$inttype = 'INT';
		else
			$inttype = 'UNSIGNED';
		if ($dbms == "OCI8")
			$orderSerNo = "LPAD(series.series_no, 255)";
			/* can't use CAST(..., INT) or TO_NUMBER(): absent values are marked by '*'
			   which yield ORA-01722
			 */
		else
			$orderSerNo = "CAST(series.series_no AS $inttype)";	/* MySQL, MSSQL, etc */
		$sql = 'SELECT pk,series_desc,series_iuid,modality,series_no,src_aet ' .
			' FROM series' .
			' WHERE study_fk=' . $authDB->sqlEscapeString($studyUid) .
				(!$disableFilter ? " AND (Modality IS NULL OR (modality!='KO' AND modality!='PR')) AND (LOWER(series_desc)!='" .
						Constants::PR_SERIES_DESC . "' OR series_desc IS NULL)"
					: '') .
			' ORDER BY ' . $orderSerNo;
		$this->log->asDump('$sql = ', $sql);

		$rs = $authDB->query($sql);
		if (!$rs)
		{
			$return['error'] = '[Structure] Database error (10), see logs';
			$this->log->asErr("query failed: '" . $authDB->getError() . "'");
			return $return;
		}

		$allSeries = array();
		while ($row1 = $authDB->fetchAssoc($rs))
			$allSeries[] = $row1;
		$authDB->free($rs);

		$i = 0;

		if (!$disableFilter)
		{
			$excludequalityseries = $this->excludeVideoQualitySeries($allSeries);
			$this->log->asDump('exclude lower quality video series: ', $excludequalityseries);
		}
		else
			$excludequalityseries = array();

		foreach ($allSeries as $row1)
		{
			$this->log->asDump('result: ', $row1);

			$modality = $this->shared->cleanDbString((string) $row1['modality']);
			$seriesUID = (string) $row1['pk'];
			$description = $this->shared->cleanDbString((string) $row1['series_desc']);
			$sourceae = (string) $row1['src_aet']; 				/* as per last series */

			//skip video quality by series description
			if (in_array($seriesUID, $excludequalityseries))
			{
				$this->log->asDump("excluded video series '$seriesUID'");
				continue;
			}

			if ($dbms == "OCI8")
				$orderInstNo = "LPAD(instance.inst_no, 255)";
				/* can't use CAST(..., INT) or TO_NUMBER(): absent values are marked by '*'
				   which yield ORA-01722
				 */
			else
				$orderInstNo = "CAST(instance.inst_no AS $inttype)";	/* MySQL, MSSQL, etc */
			$sql = 'SELECT file_ref.instance_fk,file_ref.pk,file_ref.filepath,filesystem.fs_uri,file_ref.file_tsuid,' .
				'instance.sop_cuid ' .
				'FROM instance, file_ref, filesystem WHERE file_ref.instance_fk=instance.pk AND' .
				" file_ref.filesystem_fk=filesystem.pk AND instance.series_fk=$seriesUID AND instance.replaced=0" .
				" ORDER BY $orderInstNo, instance.content_date, instance.content_time, file_ref.pk DESC";
			$this->log->asDump('$sql = ', $sql);

			$rs2 = $authDB->query($sql);
			if (!$rs2)
			{
				$return['error'] = '[Structure] Database error (11), see logs';
				$this->log->asErr("query failed: '" . $authDB->getError() . "'");
				return $return;
			}

			$j = 0;
			$return[$i]['count'] = 0;
			$instance_fk = -1;
			while ($row2 = $authDB->fetchAssoc($rs2))
			{
				$this->log->asDump('result: ', $row2);

				if ($instance_fk == $row2["instance_fk"])
					continue;		/* sometimes the `files` table contains duplicates */
				$instance_fk = $row2["instance_fk"];

				$return[$i]["count"]++;
				$return[$i][$j]["id"] = (string) $row2["pk"];
				$return[$i][$j]["numframes"] = 0;

				$path = PathUtils::stripUriPrefix((string) $row2["fs_uri"]);
				$path .= DIRECTORY_SEPARATOR . $row2["filepath"];
				$return[$i][$j]["path"] = $this->fp->toLocal(urldecode($path));		/* urldecode: embedded spaces etc */

				$return[$i][$j]["xfersyntax"] = (string) $row2["file_tsuid"];
				$return[$i][$j]["bitsstored"] = "8";
				$return[$i][$j]['sopclass'] = (string)$row2['sop_cuid'];
				$j++;
			}

			/* mark video files with a magic value .numframes = -99 */
			for ($p = 0; $p < $j; $p++)
			{
				$ts = $return[$i][$p]['xfersyntax'];
				if (($ts == '1.2.840.10008.1.2.4.100') || ($ts == '1.2.840.10008.1.2.4.103') ||
						($ts == '1.2.840.10008.1.2.4.102') || ($ts == 'MP4'))
					$return[$i][$p]['numframes'] = '-99';
			}

			/* avoid empty series (images might be filtered out by SOP Class etc) */
			if (!$j)
				unset($return[$i]);
			else
			{
				$return['count']++;
				$return[$i]['id'] = $seriesUID;
				$return[$i]['description'] = $cs->utf8Encode($description);
				$return[$i]['modality'] = $modality;
				$i++;
			}

			$authDB->free($rs2);
		}
		$return['sourceae'] = $cs->utf8Encode($sourceae);

		if ($return['count'] > 0)
			$return['error'] = '';
		else
			if (empty($return['error']))
				$return['error'] = "No images to display\n(some might have been skipped)";

		$this->log->asDump('$return = ', $return);
		$this->log->asDump('end ' . __METHOD__);
		return $return;
	}


	public function studyListSeries($studyUid)
	{
		if (strlen($this->commonData['pacs_gateway_addr']))
		{
			return $this->gw->studyListSeries($studyUid);
		}

		$return = array();
		$return['count'] = 0;
		$return['error'] = '';

		$this->log->asDump('begin ' . __METHOD__ . '(', $studyUid, ')');

		$authDB = $this->authDB;
		if (!$authDB->isAuthenticated())
		{
			$err = 'not authenticated';
			$this->log->asErr($err);
			$return['error'] = $err;
			return $return;
		}

		$dbms = $authDB->getDbms();
		if (($dbms == 'MSSQL') || ($dbms == 'SQLSRV') || ($dbms == 'POSTGRESQL'))
			$inttype = 'INT';
		else
			$inttype = 'UNSIGNED';
		if ($dbms == "OCI8")
			$orderSerNo = "LPAD(series.series_no, 255)";
			/* can't use CAST(..., INT) or TO_NUMBER(): absent values are marked by '*'
			   which yield ORA-01722
			 */
		else
			$orderSerNo = "CAST(series.series_no AS $inttype)";	/* MySQL, MSSQL, etc */
		$sql = 'SELECT pk' .
			' FROM series' .
			' WHERE study_fk=' . $authDB->sqlEscapeString($studyUid) .
				" AND (modality IS NULL OR (modality!='KO' AND modality!='PR'))" .
			' ORDER BY ' . $orderSerNo;
		$this->log->asDump('$sql = ', $sql);

		$rs = $authDB->query($sql);
		if (!$rs)
		{
			$return['error'] = '[Structure] Database error (12), see logs';
			$this->log->asErr("query failed: '" . $authDB->getError() . "'");
			return $return;
		}

		$count = 0;
		while ($row = $authDB->fetchAssoc($rs))
		{
			$this->log->asDump('result: ', $row);

			$return[$count++] = (string) $row['pk'];
		}
		$return['count'] = $count;

		$this->log->asDump('$return = ', $return);
		$this->log->asDump('end ' . __METHOD__);
		return $return;
	}


	public function studyHasReport($studyUid)
	{
		$this->log->asDump('begin ' . __METHOD__);

		$return = array();
		$return['error'] = '';
		$return['notes'] = 2;		/* unknown or can't detect */

		$authDB = $this->authDB;
		$dbms = $this->commonData['dbms'];

		$limit_pre = '';
		$limit_suf = '';
		if (($dbms == 'MSSQL') || ($dbms == 'SQLSRV'))
			$limit_pre = ' TOP(1)';
		else
			if ($dbms != "OCI8")
				$limit_suf = ' LIMIT 1';

		if (isset($_SESSION[$authDB->sessionHeader.'notesExsist']) &&
			$_SESSION[$authDB->sessionHeader.'notesExsist'])
		{
			$sql = "SELECT$limit_pre study_fk FROM studynotes WHERE study_fk='" .
				$authDB->sqlEscapeString($studyUid) . "'$limit_suf";
			if ($dbms == "OCI8")
				$sql = "SELECT * FROM ($sql) WHERE ROWNUM <= 1";
		}
		else
		{
			$return['notes'] = 2;
			return $return;
		}
		$this->log->asDump('$sql = ', $sql);

		$rs = $authDB->query($sql);
		if (!$rs)
		{
			$return['error'] = '[Structure] Database error (13), see logs';
			$this->log->asErr("query failed: '" . $authDB->getError() . "'");

			return $return;
		}

		$r = $authDB->fetchNum($rs);
		$authDB->free($rs);
		$this->log->asDump('result: ', $r);

		$return['notes'] = (int) is_array($r);

		$this->log->asDump('$return = ', $return);
		$this->log->asDump('end ' . __METHOD__);
		return $return;
	}


	public function collectRelatedVideoQualities($imageUid)
	{
		$this->log->asDump('begin ' . __METHOD__ . '(', $imageUid, ')');

		$return = array();
		$return['error'] = 'not authenticated';
		$return['quality'] = array();

		$authDB = $this->authDB;
		if (!$authDB->isAuthenticated())
		{
			$this->log->asErr($return['error']);
			return $return;
		}

		if (trim($imageUid) == '')
		{
			$return['error'] = 'required parameter(s) are missing';
			$this->log->asErr($return['error']);
			return $return;
		}
		$return['error'] = '';

		//get info about image
		$return = $this->getImageStudySeriesData($imageUid);
		if ($return['error'] != '')
		{
			$this->log->asErr($return['error']);
			return $return;
		}
		$studyuid = $authDB->sqlEscapeString($return['studyuid']);
		$currentseriesuid = $authDB->sqlEscapeString($return['seriesuid']);
		$currentseriesdescription = $return['seriesdescription'];
		$instance = $return['instance'];
		$currentseriesnumb = $return['seriesnumb'];

		$originalqualityname = 'Original';
		$originalquality = $this->seriesIsVideoQuality($currentseriesdescription);
		if ($originalquality !== false)
		{
			//not original - set original series number
			$originalqualityname = $originalquality[0];
			$currentseriesnumb = $originalquality[1];
		}

		//clear
		unset($return['studyuid']);
		unset($return['seriesuid']);
		unset($return['seriesdescription']);
		unset($return['seriesnumb']);
		unset($return['instance']);

		$return['quality'] = array();

		$dbms = $authDB->getDbms();
		if (($dbms == 'MSSQL') || ($dbms == 'SQLSRV') || ($dbms == 'POSTGRESQL'))
			$inttype = 'INT';
		else
			$inttype = 'UNSIGNED';

		// collect other series from the same study
		if ($dbms == "OCI8")
			$orderSerNo = "LPAD(series.series_no, 255)";
			/* can't use CAST(..., INT) or TO_NUMBER(): absent values are marked by '*'
			   which yield ORA-01722
			 */
		else
			$orderSerNo = "CAST(series.series_no AS $inttype)";	/* MySQL, MSSQL, etc */
		$sql = 'SELECT pk, series_desc' .
			' FROM series' .
			" WHERE study_fk=$studyuid AND pk!=$currentseriesuid" .
			' ORDER BY ' . $orderSerNo;
		$this->log->asDump('$sql = ', $sql);

		$rs = $authDB->query($sql);
		if (!$rs)
		{
			$return['error'] = '[Structure] Database error (14), see logs';
			$this->log->asErr("query failed: '" . $authDB->getError() . "'");
			return $return;
		}
		$allSeries = array();
		while ($row1 = $authDB->fetchAssoc($rs))
			$allSeries[] = $row1;
		$authDB->free($rs);
		if (count($allSeries) == 0)
		{
			/* no sense to continue if no other series was found */
			$this->log->asDump('return: ', $return);
			return $return;
		}

		$i = 0;
		foreach ($allSeries as $row1)
		{
			$seriesuid = '';
			$description = '';
			$this->log->asDump('result/1: ', $row1);

			$description = $this->shared->cleanDbString($row1['series_desc']);
			$seriesuid = $row1['pk'];

			//skip video quality by series description
			$quality = $this->seriesIsVideoQuality($description);
			$this->log->asDump('quality: ', $quality);

			if ($quality === false)
				continue;

			//series number is the same as in series description - for the same series
			if ((int)$quality[1] != (int)$currentseriesnumb)
			{
				$this->log->asDump('not for this series');
				continue;
			}

			//select images from other series with correct instance
			$sql = 'SELECT file_ref.pk' .
				' FROM instance, file_ref' .
				' WHERE file_ref.instance_fk=instance.pk' .
					' AND instance.series_fk=' . $authDB->sqlEscapeString($seriesuid) .
					' AND instance.inst_no=' . $authDB->sqlEscapeString($instance);
			$this->log->asDump('$sql = ', $sql);

			$rs2 = $authDB->query($sql);
			if (!$rs2)
			{
				$return['error'] = '[Structure] Database error (15), see logs';
				$this->log->asErr("query failed: '" . $authDB->getError() . "'");
				return $return;
			}

			while ($row2 = $authDB->fetchAssoc($rs2))
			{
				$this->log->asDump("result/2: ", $row2);
				if (trim($row2['pk']) == '')
					continue;

				$return['quality'][] = array('quality' => $quality[0],
					'imageid' => $row2['pk']);
			}
			$authDB->free($rs2);
		}

		//add original or also with quality
		if (!empty($return['quality']) ||
			($originalqualityname != 'Original'))
		{
			array_unshift($return['quality'],
				array('quality' => $originalqualityname, 'imageid' => $imageUid));
		}

		$this->log->asDump('$return = ', $return);
		$this->log->asDump('end ' . __METHOD__);

		return $return;
	}
}
