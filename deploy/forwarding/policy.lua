local domain = assert(os.getenv('FORWARD_SIGNING_DOMAIN')):lower()
local service = assert(os.getenv('FORWARD_SERVICE_ADDRESS')):lower()
local mx = assert(os.getenv('MX_HOST')):lower()

local function submission_profile(task)
  return tostring(task:get_request_header('MTA-Name') or '')
end

function mailmm_signing_key(task, profile)
  if submission_profile(task) ~= profile then return nil end
  if profile == 'mailmm-manual' then
    local from = task:get_from('mime')
    if not from or #from ~= 1 or from[1].addr:lower() ~= service then return nil end
    return {domain = domain, selector = 'mmfwd1', key = '/run/mailmm-keys/dkim.key'}
  end

  -- The application prepends the result of the original SMTP session and
  -- rejects inbound messages that try to claim this MX's authentication ID.
  local ar = task:get_header('Authentication-Results') or ''
  if (ar:match('^%s*([^;%s]+)%s*;') or ''):lower() ~= mx then return nil end
  return {domain = domain, selector = 'mmarc1', key = '/run/mailmm-keys/arc.key'}
end

rspamd_config:register_symbol {
  name = 'MAILMM_REQUIRE_SIGNATURE',
  type = 'postfilter',
  flags = 'ignore_passthrough',
  priority = -10,
  callback = function(task)
    local profile = submission_profile(task)
    local expected = ({['mailmm-manual'] = 'DKIM_SIGNED', ['mailmm-auto'] = 'ARC_SIGNED'})[profile]
    if not expected or not task:has_symbol(expected) then
      require('rspamd_logger').warnx(task, 'required signing outcome unavailable for profile %s', profile or 'unknown')
      task:set_pre_result('soft reject', 'Required MailMM signature unavailable', 'mailmm')
    end
  end
}
