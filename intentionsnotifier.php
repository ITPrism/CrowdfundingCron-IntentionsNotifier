<?php
/**
 * @package      Crowdfunding
 * @subpackage   Plug-ins
 * @author       Todor Iliev
 * @copyright    Copyright (C) 2016 Todor Iliev <todor@itprism.com>. All rights reserved.
 * @license      GNU General Public License version 3 or later; see LICENSE.txt
 */

// no direct access
defined('_JEXEC') or die;

/**
 * Crowdfunding CRON Intentions Notifier Plug-in
 *
 * @package      Crowdfunding
 * @subpackage   Plug-ins
 */
class plgCrowdfundingCronIntentionsNotifier extends JPlugin
{
    public function onCronNotify($context)
    {
        if (strcmp('com_crowdfunding.cron.notify.intentions', $context) !== 0) {
            return;
        }

        jimport('Prism.init');
        jimport('Crowdfunding.init');

        $period = (!$this->params->get('period', 7)) ? 7 : $this->params->get('period', 7);

        $intentions = $this->getIntentions($period);

        if (count($intentions) > 0) {
            $this->loadLanguage();

            // Send messages.
            jimport('Emailtemplates.init');
            $emailTemplate = new Emailtemplates\Email();
            $emailTemplate->setDb(JFactory::getDbo());
            $emailTemplate->load($this->params->get('email_id'));

            if (!$emailTemplate->getId()) {
                throw new RuntimeException(JText::_('PLG_CROWDFUNDINGCRON_INTENTIONS_NOTIFIER_ERROR_INVALID_EMAIL_TEMPLATE'));
            }

            $app       = JFactory::getApplication('site');
            $emailMode = $this->params->get('email_mode', 'plain');

            // Set name and e-mail address of the sender in the mail template.
            if (!$emailTemplate->getSenderName()) {
                $emailTemplate->setSenderName($app->get('fromname'));
            }
            if (!$emailTemplate->getSenderEmail()) {
                $emailTemplate->setSenderEmail($app->get('mailfrom'));
            }

            $componentParams = JComponentHelper::getParams('com_crowdfunding');

            $intentionsIds = array();

            foreach ($intentions as $intention) {
                $email  = clone $emailTemplate;
                
                $intentionsIds[] = (int)$intention['id'];

                $image = '';
                if (!empty($intention['image'])) {
                    $image = $this->params->get('domain') . $componentParams->get('images_directory'). '/'. $intention['image'];
                    $image = '<img src="'.$image.'" width="'.$componentParams->get('image_width').'" height="'.$componentParams->get('image_width').'" />';
                }

                // Parse and send message to users.
                $data = array(
                    'RECIPIENT_NAME'      => $intention['name'],
                    'ITEM_TITLE'          => $intention['title'],
                    'ITEM_URL'            => $this->params->get('domain') . JRoute::_(CrowdfundingHelperRoute::getBackingRoute($intention['slug'], $intention['catslug'], 'default', $intention['reward_id'])),
                    'ITEM_IMAGE'          => $image,
                    'ITEM_DESCRIPTION'    => $intention['short_desc'],
                    'REWARD_TITLE'        => $intention['reward_title'],
                    'REWARD_DESCRIPTION'  => $intention['reward_description'],
                );

                $email->parse($data);

                $mailer = JFactory::getMailer();
                if (strcmp('html', $emailMode) === 0) { // Send as HTML message
                    $result = $mailer->sendMail($email->getSenderEmail(), $email->getSenderName(), $intention['email'], $email->getSubject(), $email->getBody($emailMode), Prism\Constants::MAIL_MODE_HTML);
                } else { // Send as plain text.
                    $result = $mailer->sendMail($email->getSenderEmail(), $email->getSenderName(), $intention['email'], $email->getSubject(), $email->getBody($emailMode), Prism\Constants::MAIL_MODE_PLAIN);
                }

                if ($result !== true) {
                    throw new RuntimeException($mailer->ErrorInfo);
                }
            }

            $intentionsIds = array_unique($intentionsIds);
            $this->removeIntentions($intentionsIds);
        }
    }

    protected function getIntentions($period)
    {
        $db = JFactory::getDbo();
        $query = $db->getQuery(true);

        $query
            ->select(
                'a.id, a.user_id, a.project_id, a.reward_id, ' .
                'b.title, b.short_desc, b.image, ' .
                'c.name, c.email, '.
                'e.title as reward_title, e.description as reward_description, '.
                $query->concatenate(array('b.id', 'b.alias'), ':') . ' AS slug, ' .
                $query->concatenate(array('d.id', 'd.alias'), ':') . ' AS catslug'
            )
            ->from($db->quoteName('#__crowdf_intentions', 'a'))
            ->innerJoin($db->quoteName('#__crowdf_projects', 'b') . ' ON a.project_id = b.id')
            ->innerJoin($db->quoteName('#__users', 'c') . ' ON a.user_id = c.id')
            ->innerJoin($db->quoteName('#__categories', 'd') . ' ON b.catid = d.id')
            ->leftJoin($db->quoteName('#__crowdf_rewards', 'e') . ' ON a.reward_id = e.id')
            ->where('a.record_date <= DATE_SUB(NOW(), INTERVAL '.$period.' DAY)')
            ->where('a.user_id > 0');

        $db->setQuery($query);
        return (array)$db->loadAssocList();
    }

    protected function removeIntentions(array $ids)
    {
        if (count($ids) > 0) {
            $db    = JFactory::getDbo();
            $query = $db->getQuery(true);

            $query
                ->delete($db->quoteName('#__crowdf_intentions'))
                ->where($db->quoteName('id') .' IN (' . implode(',', $ids) . ')');

            $db->setQuery($query);
            $db->execute();
        }
    }
}
