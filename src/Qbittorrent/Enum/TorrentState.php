<?php

namespace Blackout\Qbittorrent\Enum;

enum TorrentState: string
{
	use \Blackout\Qbittorrent\Trait\HasLabel;
	
	case ERROR = 'error';
	case MISSING_FILES = 'missingFiles';

	case DOWNLOADING = 'downloading';
	case UPLOADING = 'uploading';

	case STOPPED_DL = 'stoppedDL';
	case STOPPED_UP = 'stoppedUP';

	case QUEUED_DL = 'queuedDL';
	case QUEUED_UP = 'queuedUP';

	case STALLED_DL = 'stalledDL';
	case STALLED_UP = 'stalledUP';

	case CHECKING_DL = 'checkingDL';
	case CHECKING_UP = 'checkingUP';
	case CHECKING_RESUME_DATA = 'checkingResumeData';

	case FORCED_DL = 'forcedDL';
	case FORCED_UP = 'forcedUP';

	case META_DL = 'metaDL';
	case FORCED_META_DL = 'forcedMetaDL';

	case MOVING = 'moving';

	case UNKNOWN = 'unknown';
}
