export function EmployeeProfileViewPage() {
  const { employeeId } = useParams();
  const { user } = useAuth();
  const navigate = useNavigate();

  const [employee, setEmployee] = useState(null);
  const [busy, setBusy] = useState(true);

  const n = useNotice();

  useEffect(() => {
    let cancelled = false;

    const loadEmployee = async () => {
      setBusy(true);

      try {
        const response = await api.get(
          `/management/employees/${employeeId}`
        );

        if (!cancelled) {
          setEmployee(response.data);
        }
      } catch (error) {
        if (!cancelled) {
          n.error(messageFrom(error));
        }
      } finally {
        if (!cancelled) {
          setBusy(false);
        }
      }
    };

    loadEmployee();

    return () => {
      cancelled = true;
    };
  }, [employeeId]);

  if (busy && !employee) {
    return <Loading />;
  }

  if (!employee) {
    return (
      <>
        <NoticeBar
          notice={n.notice}
          clear={n.clear}
        />

        <EmptyState title="Employee not found" />
      </>
    );
  }

  const name =
    `${employee.first_name || ''} ${employee.last_name || ''}`.trim() ||
    'Employee';

  const initials =
    `${employee.first_name?.[0] || ''}${employee.last_name?.[0] || ''}`
      .toUpperCase() || 'VA';

  const base = roleBase(user?.role);

  /*
   * Complete employee information.
   *
   * Existing database/API fields are used here.
   * No duplicate DOB/profile fields are introduced.
   */
  const detailRows = [
    ['Employee #', employee.employee_code],
    ['Email', employee.email],
    ['Phone', employee.phone],
    ['Location', employee.location],
    ['Timezone', employee.timezone],

    ['Department', employee.department?.name],

    ['Designation', employee.designation?.name],

    [
      'Employment type',
      employee.employment_type?.name ||
        employee.employmentType?.name
    ],

    [
      'Shift type',
      employee.shift_type?.name ||
        employee.shiftType?.name
    ],

    [
      'Status',
      employee.employee_status?.name
    ],

    ['Expertise', employee.expertise?.name],

    [
      'Reporting manager',
      employee.reporting_manager?.name ||
        employee.reportingManager?.name
    ],

    [
      'Joining date',
      employee.date_of_joining
        ? String(employee.date_of_joining).slice(0, 10)
        : ''
    ],

    [
      'Regularisation date',
      employee.date_of_regularisation
        ? String(employee.date_of_regularisation).slice(0, 10)
        : ''
    ],

    ['Source of hire', employee.source_of_hire],

    ['Gender', employee.gender],

    ['Marital status', employee.marital_status],

    [
      'Date of birth',
      employee.date_of_birth
        ? String(employee.date_of_birth).slice(0, 10)
        : ''
    ],

    ['Age', employee.age],

    [
      'Salary PKR',
      employee.salary_pkr
        ? `PKR ${Number(employee.salary_pkr).toLocaleString()}`
        : ''
    ],

    [
      'Salary USD',
      employee.salary_usd
        ? money(employee.salary_usd)
        : ''
    ],

    [
      'Profile completed',
      employee.profile_completed ? 'Yes' : 'No'
    ],

    [
      'About employee',
      employee.about_me_notes
    ]
  ];

  /*
   * Employee documents already stored on employee record.
   */
  const employeeDocuments = Array.isArray(employee.upload_documents)
    ? employee.upload_documents
    : [];

  return (
    <>
      {/* ======================================================
          PAGE HEADER
          ====================================================== */}

      <PageHeader
        eyebrow="VA profile"
        title={name}
        description="Complete employee identity, personal, work, compensation and document record."
        actions={
          <>
            <Button
              variant="secondary"
              onClick={() => navigate(`${base}/employees`)}
            >
              Back to employees
            </Button>

            {user?.role === 'admin' && (
              <Button
                icon={Pencil}
                onClick={() =>
                  navigate(`/admin/employees?edit=${employee.id}`)
                }
              >
                Edit employee
              </Button>
            )}
          </>
        }
      />

      <NoticeBar
        notice={n.notice}
        clear={n.clear}
      />


      {/* ======================================================
          EMPLOYEE HERO
          ====================================================== */}

      <div className="employee-profile-hero">

        <div className="profile-photo">
          <AvatarMedia
            src={employee.profile_picture}
            alt={name}
            fallback={initials}
          />
        </div>

        <div className="employee-profile-hero-copy">

          <span className="eyebrow">
            {employee.employee_code || 'VA'}
          </span>

          <h2>{name}</h2>

          <p>
            {employee.email || 'No email'}

            {' · '}

            {employee.phone || 'No phone'}
          </p>

        </div>

        <Badge
          tone={
            employee.profile_completed
              ? 'success'
              : 'warning'
          }
        >
          {employee.profile_completed
            ? 'Profile complete'
            : 'Profile incomplete'}
        </Badge>

      </div>


      {/* ======================================================
          MAIN PROFILE LAYOUT

          LEFT  = 8 columns
          RIGHT = 4 columns
          ====================================================== */}

      <div className="employee-profile-grid employee-profile-grid-8-4">


        {/* ====================================================
            LEFT SIDE
            COMPLETE EMPLOYEE INFORMATION
            ==================================================== */}

        <Card
          className="employee-profile-information-card"
          title="Complete employee information"
        >

          <div className="detail-list employee-profile-detail-grid">

            {detailRows.map(([label, value]) => (

              <div
                key={label}
                className={
                  label === 'About employee'
                    ? 'employee-profile-detail-full'
                    : ''
                }
              >

                <span>
                  {label}
                </span>

                <strong>
                  {value === null ||
                  value === undefined ||
                  value === ''
                    ? '—'
                    : value}
                </strong>

              </div>

            ))}

          </div>

        </Card>


        {/* ====================================================
            RIGHT SIDE
            DOCUMENTS + PROFILE STATUS
            ==================================================== */}

        <div className="stack employee-profile-right-column">


          {/* DOCUMENTS */}

          <Card
            className="employee-profile-documents-card"
            title="Documents"
            subtitle="Files stored on the employee record."
          >

            {employeeDocuments.length ? (

              <div className="employee-document-list">

                {employeeDocuments.map((document, index) => {

                  /*
                   * Supports both:
                   * ["url1", "url2"]
                   *
                   * and possible object format:
                   * [{url: "...", name: "..."}]
                   */

                  const url =
                    typeof document === 'string'
                      ? document
                      : document?.url ||
                        document?.path ||
                        document?.file_url ||
                        '';

                  const documentName =
                    typeof document === 'object'
                      ? document?.name ||
                        document?.filename ||
                        document?.title
                      : null;

                  if (!url) {
                    return null;
                  }

                  return (

                    <div
                      className="employee-document-item"
                      key={`${url}-${index}`}
                    >

                      <div className="employee-document-copy">

                        <strong>
                          {documentName ||
                            `Document ${index + 1}`}
                        </strong>

                        <span>
                          Employee document
                        </span>

                      </div>

                      <a
                        className="btn btn-secondary btn-link"
                        href={url}
                        target="_blank"
                        rel="noreferrer"
                      >
                        View
                      </a>

                      <a
                        className="btn btn-ghost btn-link"
                        href={url}
                        download
                      >
                        Download
                      </a>

                    </div>

                  );

                })}

              </div>

            ) : (

              <EmptyState
                title="No employee documents"
                description="Documents uploaded for this employee will appear here."
              />

            )}

          </Card>


          {/* PROFILE STATUS */}

          <Card
            className="employee-profile-status-card"
            title="Profile status"
          >

            <div className="detail-list employee-profile-status-list">


              <div>

                <span>
                  Completion
                </span>

                <strong>

                  <Badge
                    tone={
                      employee.profile_completed
                        ? 'success'
                        : 'warning'
                    }
                  >
                    {employee.profile_completed
                      ? 'Complete'
                      : 'Incomplete'}
                  </Badge>

                </strong>

              </div>


              <div>

                <span>
                  Date of birth
                </span>

                <strong>
                  {employee.date_of_birth
                    ? String(
                        employee.date_of_birth
                      ).slice(0, 10)
                    : '—'}
                </strong>

              </div>


              <div>

                <span>
                  Timezone
                </span>

                <strong>
                  {employee.timezone || '—'}
                </strong>

              </div>


              <div>

                <span>
                  Employee #
                </span>

                <strong>
                  {employee.employee_code || '—'}
                </strong>

              </div>


              <div>

                <span>
                  Status
                </span>

                <strong>
                  {employee.employee_status?.name || '—'}
                </strong>

              </div>


              <div>

                <span>
                  Department
                </span>

                <strong>
                  {employee.department?.name || '—'}
                </strong>

              </div>


              <div>

                <span>
                  Designation
                </span>

                <strong>
                  {employee.designation?.name || '—'}
                </strong>

              </div>


            </div>

          </Card>

        </div>

      </div>
    </>
  );
}