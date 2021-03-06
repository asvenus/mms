<?php

class XenForo_Install_Controller_Upgrade extends XenForo_Install_Controller_Abstract
{
	protected function _preDispatch($action)
	{
		if (!$this->_getInstallModel()->isInstalled())
		{
			throw $this->responseException(
				$this->responseRedirect(XenForo_ControllerResponse_Redirect::RESOURCE_CANONICAL, 'index.php?install/')
			);
		}

		if (strtolower($action) !== 'login')
		{
			$visitor = XenForo_Visitor::getInstance();
			if (!$visitor['is_admin'])
			{
				throw $this->responseException(
					$this->responseReroute(__CLASS__, 'login')
				);
			}

			if (!$visitor->hasAdminPermission('upgradeXenForo'))
			{
				throw new XenForo_Exception(new XenForo_Phrase('you_do_not_have_permission_upgrade'), true);
			}
		}
	}

	/**
	* Setup the session.
	*
	* @param string $action
	*/
	protected function _setupSession($action)
	{
		if (XenForo_Application::isRegistered('session'))
		{
			return;
		}

		$session = new XenForo_Session(array('admin' => true));
		XenForo_Application::set('session', $session);
		$session->start();

		XenForo_Visitor::setup($session->get('user_id'));
	}

	public function actionIndex()
	{
		if ($this->_getUpgradeModel()->getNewestUpgradeVersionId() > XenForo_Application::$versionId)
		{
			return $this->responseError(new XenForo_Phrase('upgrade_found_newer_than_version'));
		}

		if ($this->_getUpgradeModel()->getLatestUpgradeVersionId() === XenForo_Application::$versionId
			&& XenForo_Application::get('options')->currentVersionId >= XenForo_Application::$versionId)
		{
			return $this->responseView('XenForo_Install_View_Upgrade_Current', 'upgrade_current');
		}

		if (class_exists('XenForo_Install_Data_FileSums'))
		{
			$hashes = XenForo_Install_Data_FileSums::getHashes();
			foreach ($hashes AS $key => $hash)
			{
				if (!preg_match('#^(\./)?(install/|library/XenForo/Install/|library/XenForo/Application.php)#', $key))
				{
					unset($hashes[$key]);
				}
			}
			$fileErrors = XenForo_Helper_Hash::compareHashes($hashes);
			$hashesExist = true;
		}
		else
		{
			$fileErrors = array();
			$hashesExist = false;
		}

		return $this->responseView('XenForo_Install_View_Upgrade_Start', 'upgrade_start', array(
			'targetVersion' => XenForo_Application::$version,
			'errors' => $this->_getInstallModel()->getRequirementErrors(),
			'fileErrors' => $fileErrors,
			'hashesExist' => $hashesExist
		));
	}

	public function actionRun()
	{
		$this->_assertPostOnly();

		$upgradeModel = $this->_getUpgradeModel();
		$lastCompletedVersion = $upgradeModel->getLatestUpgradeVersionId();

		if ($lastCompletedVersion === XenForo_Application::$versionId)
		{
			return $this->actionRebuild();
		}

		$input = $this->_input->filter(array(
			'run_version' => XenForo_Input::UINT,
			'step' => XenForo_Input::STRING,
			'position' => XenForo_Input::UINT,
			'step_data' => XenForo_Input::JSON_ARRAY
		));

		if (!$input['run_version'])
		{
			$input['run_version'] = $upgradeModel->getNextUpgradeVersionId($lastCompletedVersion);
			$input['step'] = 1;

			if ($input['run_version'])
			{
				if ($input['run_version'] > XenForo_Application::$versionId)
				{
					return $this->responseError(new XenForo_Phrase('upgrade_found_newer_than_version'));
				}

				$upgrade = $upgradeModel->getUpgrade($input['run_version']);
			}
			else
			{
				$upgrade = false;
			}
		}
		else
		{
			$upgrade = $upgradeModel->getUpgrade($input['run_version']);
		}

		if (!$upgrade)
		{
			$upgradeModel->insertUpgradeLog();
			return $this->actionRebuild();
		}

		if (!$input['step'])
		{
			$input['step'] = 1;
		}

		if (method_exists($upgrade, 'step' . $input['step']))
		{
			$result = $upgrade->{'step' . $input['step']}($input['position'], $input['step_data'], $this);
		}
		else
		{
			$result = 'complete';
		}

		if ($result instanceof XenForo_ControllerResponse_Abstract)
		{
			return $result;
		}

		$stepMessage = '';
		$stepData = false;

		if ($result === 'complete')
		{
			$upgradeModel->insertUpgradeLog($input['run_version']);

			$viewParams = array(
				'newRunVersion' => '',
				'newStep' => '',
				'versionName' => $upgrade->getVersionName(),
				'step' => $input['step']
			);
		}
		else
		{
			if ($result === true)
			{
				$result = $input['step'] + 1;
			}
			else if (is_array($result))
			{
				$input['position'] = $result[0];
				$stepMessage = $result[1];
				if (!empty($result[2]))
				{
					$stepData = $result[2];
				}

				$result = $input['step']; // stay on same step
			}

			$viewParams = array(
				'newRunVersion' => $input['run_version'],
				'newStep' => $result,
				'position' => $input['position'],
				'stepMessage' => $stepMessage,
				'stepData' => $stepData,
				'versionName' => $upgrade->getVersionName(),
				'step' => $input['step']
			);
		}

		return $this->responseView('XenForo_Install_View_Upgrade_Run', 'upgrade_run', $viewParams);
	}

	public function actionRebuild()
	{
		$input = $this->_input->filter(array(
			'caches' => XenForo_Input::JSON_ARRAY,
			'position' => XenForo_Input::UINT,

			'cache' => XenForo_Input::STRING,
			'options' => XenForo_Input::ARRAY_SIMPLE,

			'process' => XenForo_Input::UINT
		));

		$doRebuild = ($this->_request->isPost() && $input['process']);
		$redirect = 'index.php?upgrade/complete';

		if (!$doRebuild)
		{
			$input['caches'] = array(
				'ImportMasterData', 'Permission',
				'ImportPhrase', 'Phrase',
				'ImportTemplate', 'Template',
				'ImportAdminTemplate', 'AdminTemplate',
				'ImportEmailTemplate', 'EmailTemplate'
			);
		}

		$output = $this->getHelper('CacheRebuild')->rebuildCache(
			$input, $redirect, 'index.php?upgrade/rebuild', $doRebuild
		);

		if ($output instanceof XenForo_ControllerResponse_Redirect)
		{
			// complete, update the version if needed
			if ($this->_getUpgradeModel()->getLatestUpgradeVersionId() === XenForo_Application::$versionId)
			{
				$this->_getUpgradeModel()->updateVersion();
			}
		}

		if ($output instanceof XenForo_ControllerResponse_Abstract)
		{
			return $output;
		}
		else
		{
			$viewParams = $output;

			return $this->responseView('XenForo_Install_View_CacheRebuild', 'cache_rebuild', $viewParams);
		}
	}

	public function actionComplete()
	{
		if (XenForo_Application::get('options')->currentVersionId == XenForo_Application::$versionId)
		{
			return $this->responseView('XenForo_Install_View_Upgrade_Complete', 'upgrade_complete', array(
				'version' => XenForo_Application::$version
			));
		}
		else
		{
			return $this->responseMessage(new XenForo_Phrase('uh_oh_upgrade_did_not_complete'));
		}
	}

	public function actionLogin()
	{
		return $this->responseView('XenForo_Install_View_Upgrade_Login', 'upgrade_login');
	}

	/**
	 * @return XenForo_Install_Model_Install
	 */
	protected function _getInstallModel()
	{
		return $this->getModelFromCache('XenForo_Install_Model_Install');
	}

	/**
	 * @return XenForo_Install_Model_Upgrade
	 */
	protected function _getUpgradeModel()
	{
		return $this->getModelFromCache('XenForo_Install_Model_Upgrade');
	}
}